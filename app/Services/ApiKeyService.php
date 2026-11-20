<?php

namespace App\Services;

use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Exceptions\BusinessLogicException;
use App\Models\ApiKey;
use App\Models\Blacklist;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Logging\SecurityLogger;
use App\Support\Tenancy\PermissionTeams;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ApiKeyService
 *
 * Servicio para gestión de API Keys:
 * - CRUD completo de API Keys
 * - Rotación de keys
 * - Validación de keys
 * - Gestión de scopes
 */
class ApiKeyService implements ApiKeyServiceInterface
{
    /**
     * Cache TTL para validaciones de API Keys (en segundos)
     */
    protected const CACHE_TTL = 300; // 5 minutos

    /**
     * Cache key prefix
     */
    protected const CACHE_PREFIX = 'api_key:';

    /**
     * Período de gracia para rotación (en días)
     */
    protected const ROTATION_GRACE_PERIOD_DAYS = 7;

    public function __construct(private readonly PlanLimitsInterface $limits) {}

    /**
     * Crear una nueva API Key
     *
     * @param  User  $user  Usuario propietario de la key
     * @param  string  $name  Nombre descriptivo
     * @param  list<string>  $scopes  Scopes permitidos
     * @param  \DateTimeInterface|null  $expiresAt  Fecha de expiración
     * @param  string  $environment  Entorno (live o test)
     * @return array<string, mixed> Array con la key completa y el modelo ApiKey
     *
     * @throws BusinessLogicException Si los scopes no son válidos
     */
    public function create(User $user, string $name, array $scopes = [], ?\DateTimeInterface $expiresAt = null, string $environment = 'live'): array
    {
        // Límite del plan: cuenta y crea bajo bloqueo de la fila del tenant (sin carrera). Las keys
        // vigentes (la antigua en periodo de gracia incluida) cuentan; ver docs/settings-and-flags.md.
        return $this->limits->guarded(
            'max_api_keys',
            fn (): int => ApiKey::query()->count(),
            fn (): array => $this->createKey($user, $name, $scopes, $expiresAt, $environment),
        );
    }

    /**
     * Alta sin comprobar el límite (la rotación reemplaza una key: no debe bloquearse en el tope).
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function createKey(User $user, string $name, array $scopes, ?\DateTimeInterface $expiresAt, string $environment): array
    {
        // Las keys de prueba no valen en producción
        if ($environment === 'test' && ! $this->testKeysAllowed()) {
            throw new BusinessLogicException('Las API Keys de prueba no están permitidas en producción', 'TEST_KEY_IN_PRODUCTION');
        }

        // Validar scopes
        $this->validateScopes($scopes);

        // Generar key completa con prefijo
        $prefix = config('api-keys.prefixes.'.$environment, 'apygg_live_');
        $randomKey = Str::random(config('api-keys.key_length', 64));
        $fullKey = $prefix.$randomKey;

        // Hash de la key completa
        $hashedKey = hash('sha256', $fullKey);

        // Crear API Key
        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $name,
            'key' => $hashedKey,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        // Limpiar cache del usuario
        $this->clearUserCache($user->id);

        // Registrar en ActivityLog
        LogService::logActivity('created', ApiKey::class, $apiKey->id, [
            'name' => $name,
            'environment' => $environment,
            'scopes' => $scopes,
        ]);

        return [
            'api_key' => $apiKey,
            'key' => $fullKey, // Solo se retorna una vez
        ];
    }

    /**
     * Actualizar una API Key
     *
     * @param  string  $keyId  ID de la key
     * @param  array<string, mixed>  $data  Datos a actualizar
     *
     * @throws ModelNotFoundException Si la key no existe
     */
    public function update(string $keyId, array $data): ApiKey
    {
        $apiKey = ApiKey::findOrFail($keyId);

        // Validar scopes si se proporcionan
        if (isset($data['scopes'])) {
            $this->validateScopes($data['scopes']);
        }

        // Guardar valores anteriores para auditoría
        $oldValues = $apiKey->only(['name', 'scopes', 'expires_at']);

        // Actualizar
        $apiKey->update($data);

        // Limpiar cache
        $this->clearKeyCache($apiKey->key);
        $this->clearUserCache($apiKey->user_id);

        // Registrar en ActivityLog
        LogService::logActivity('updated', ApiKey::class, $apiKey->id, [
            'before' => $oldValues,
            'after' => $apiKey->only(['name', 'scopes', 'expires_at']),
        ]);

        return $apiKey->fresh();
    }

    /**
     * Eliminar (revocar) una API Key
     *
     * @param  string  $keyId  ID de la key
     *
     * @throws ModelNotFoundException Si la key no existe
     */
    public function revoke(string $keyId): bool
    {
        $apiKey = ApiKey::findOrFail($keyId);

        // Limpiar cache antes de eliminar
        $this->clearKeyCache($apiKey->key);
        $this->clearUserCache($apiKey->user_id);

        // Soft delete
        $deleted = $apiKey->delete();

        // Registrar en ActivityLog
        LogService::logActivity('deleted', ApiKey::class, $apiKey->id, [
            'name' => $apiKey->name,
        ]);

        return $deleted;
    }

    public function revokeAllForUser(int $userId): int
    {
        return TenantQuery::withoutTenant(function () use ($userId) {
            $keys = ApiKey::query()->where('user_id', $userId)->get();

            foreach ($keys as $apiKey) {
                $this->clearKeyCache($apiKey->key);
                $apiKey->delete();
                LogService::logActivity('deleted', ApiKey::class, $apiKey->id, ['name' => $apiKey->name, 'reason' => 'blacklist']);
            }

            $this->clearUserCache($userId);

            return $keys->count();
        }, 'revocar las API keys de un usuario bloqueado');
    }

    /**
     * Rotar una API Key (crear nueva y mantener antigua por período de gracia)
     *
     * @param  string  $keyId  ID de la key a rotar
     * @param  string|null  $newName  Nombre para la nueva key (opcional)
     * @param  list<string>|null  $newScopes  Scopes para la nueva key (opcional)
     * @param  string  $environment  Entorno (live o test)
     * @return array<string, mixed> Array con la nueva key completa y el modelo ApiKey
     *
     * @throws ModelNotFoundException Si la key no existe
     */
    public function rotate(string $keyId, ?string $newName = null, ?array $newScopes = null, string $environment = 'live'): array
    {
        $oldApiKey = ApiKey::findOrFail($keyId); // una key revocada (borrada) no se encuentra

        if ($oldApiKey->isExpired()) {
            throw new BusinessLogicException('No se puede rotar una API Key caducada', 'API_KEY_EXPIRED');
        }

        $user = $oldApiKey->user;

        if (! $user) {
            throw BusinessLogicException::ruleViolation('El usuario asociado a la API Key no existe');
        }

        // Usar valores de la key antigua si no se proporcionan nuevos
        $name = $newName ?? $oldApiKey->name.' (rotated)';
        $scopes = $newScopes ?? ($oldApiKey->scopes ?? []);
        $expiresAt = $oldApiKey->expires_at;

        // Nueva key + periodo de gracia de la antigua en una sola transacción
        return DB::transaction(function () use ($user, $name, $scopes, $expiresAt, $environment, $oldApiKey) {
            $result = $this->createKey($user, $name, $scopes, $expiresAt, $environment);
            $newApiKey = $result['api_key'];

            // La key antigua caduca al acabar el periodo de gracia, pero nunca DESPUÉS de su
            // propia fecha de caducidad (no se alarga una key que ya iba a morir)
            $gracePeriodExpiresAt = now()->addDays(self::ROTATION_GRACE_PERIOD_DAYS);
            if ($oldApiKey->expires_at && $oldApiKey->expires_at->lt($gracePeriodExpiresAt)) {
                $gracePeriodExpiresAt = $oldApiKey->expires_at->copy();
            }
            $oldApiKey->update(['expires_at' => $gracePeriodExpiresAt]);
            $this->clearKeyCache($oldApiKey->key);

            LogService::logActivity('updated', ApiKey::class, $oldApiKey->id, [
                'action' => 'rotated',
                'old_key_id' => $oldApiKey->id,
                'new_key_id' => $newApiKey->id,
                'grace_period_expires_at' => $gracePeriodExpiresAt->toIso8601String(),
            ]);

            return [
                'api_key' => $newApiKey,
                'key' => $result['key'],
                'old_key_expires_at' => $gracePeriodExpiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * Autenticar un request por API Key (driver del guard `api-key`).
     *
     * Devuelve el usuario dueño de la key, o null si falta, es inválida, está revocada o
     * caducada. Deja en el request `authenticated_via` y `api_key_id` para `api-key-scope:`.
     */
    public function authenticate(Request $request): ?User
    {
        $key = $request->header('X-API-Key');

        if (! $key && preg_match('/^Bearer\s+(.+)$/i', (string) $request->header('Authorization'), $m)) {
            $key = $m[1];
        }

        if (! $key) {
            return null;
        }

        $apiKey = $this->validate($key);
        $user = $apiKey?->user;

        // Multiempresa: la key PERTENECE a un tenant y fija su contexto (sin cabecera X-Tenant).
        // Falla cerrado: una key sin tenant, de un tenant no activo o de un usuario que ya no es
        // miembro no autentica.
        if ($apiKey && $user instanceof User && ! $this->bindTenant($apiKey, $user)) {
            $user = null;
        }

        // Identidad bloqueada por un administrador: sus API keys dejan de autenticar.
        if ($user instanceof User && Blacklist::blocksUser($user)) {
            $user = null;
        }

        if (! $apiKey || ! $user instanceof User) {
            SecurityLogger::logSuspiciousActivity(
                'Intento de acceso con API Key inválida, revocada o expirada',
                null,
                ['api_key_prefix' => substr($key, 0, 12).'...', 'ip_address' => $request->ip()],
                $request
            );

            return null;
        }

        $request->attributes->set('authenticated_via', 'api-key');
        $request->attributes->set('api_key_id', $apiKey->id);

        // last_used_at como mucho una vez por minuto (evita una escritura por request)
        if ($apiKey->last_used_at === null || $apiKey->last_used_at->lt(now()->subMinute())) {
            try {
                $apiKey->updateLastUsed();
            } catch (\Throwable $e) {
                LogService::warning('No se pudo actualizar last_used_at de la API Key', ['api_key_id' => $apiKey->id, 'error' => $e->getMessage()]);
            }
        }

        SecurityLogger::logApiKeyUsage($apiKey, $request);

        return $user;
    }

    /**
     * Fija el TenantContext del tenant de la key. Devuelve false si la key no puede operar.
     */
    protected function bindTenant(ApiKey $apiKey, User $user): bool
    {
        $context = app(TenantContext::class);

        if (! $context->enabled()) {
            return true;
        }

        $tenant = ($tenantId = $apiKey->getAttribute('tenant_id')) !== null ? Tenant::query()->find((int) $tenantId) : null;

        if (! $tenant instanceof Tenant || ! $tenant->isActive() || ! $user->canEnterTenant($tenant)) {
            return false;
        }

        $context->set($tenant);
        PermissionTeams::forgetUserRelations($user);

        // Esta petición no pasa por el middleware `tenant` (que limpia en terminate): se limpia al terminar.
        app()->terminating(fn () => $context->clear());

        return true;
    }

    /**
     * Validar una API Key
     *
     * @param  string  $key  Key completa (con prefijo)
     * @return ApiKey|null ApiKey si es válida, null si no
     */
    public function validate(string $key): ?ApiKey
    {
        // La key se busca ANTES de conocer el tenant (ella lo fija): consulta de plataforma, sin
        // filtro y con caché global (el hash de la key es único en todo el sistema).
        return TenantQuery::withoutTenant(fn () => $this->validateKey($key), 'autenticación por API key', false);
    }

    protected function validateKey(string $key): ?ApiKey
    {
        // Una key de prueba no vale en producción
        if (! $this->testKeysAllowed() && str_starts_with($key, (string) config('api-keys.prefixes.test', 'apygg_test_'))) {
            return null;
        }

        // Hash de la key
        $hashedKey = hash('sha256', $key);

        // Buscar en cache primero
        $cacheKey = self::CACHE_PREFIX.$hashedKey;
        $cached = CacheService::get($cacheKey);

        if ($cached !== null) {
            // Si está en cache como inválida, retornar null
            if ($cached === false) {
                return null;
            }

            // Si está en cache como válida, buscar el modelo
            $apiKey = ApiKey::find($cached);
            if ($apiKey && $apiKey->isActive()) {
                return $apiKey;
            }
        }

        // Buscar en base de datos
        $apiKey = ApiKey::where('key', $hashedKey)
            ->whereNull('deleted_at')
            ->first();

        if (! $apiKey) {
            // Cachear como inválida
            CacheService::set($cacheKey, false, self::CACHE_TTL);

            return null;
        }

        // Verificar expiración
        if ($apiKey->isExpired()) {
            // Cachear como inválida
            CacheService::set($cacheKey, false, self::CACHE_TTL);

            return null;
        }

        // Cachear como válida
        CacheService::set($cacheKey, $apiKey->id, self::CACHE_TTL);

        return $apiKey;
    }

    /**
     * Buscar una API Key por ID
     *
     * @param  string  $keyId  ID de la key
     *
     * @throws ModelNotFoundException Si la key no existe
     */
    public function find(string $keyId): ApiKey
    {
        return ApiKey::findOrFail($keyId);
    }

    /**
     * Listar API Keys de un usuario
     *
     * @param  User  $user  Usuario propietario
     * @param  int  $perPage  Elementos por página
     * @return LengthAwarePaginator<int, ApiKey>
     */
    public function list(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return ApiKey::byUser($user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Verificar si una key tiene un scope específico
     *
     * @param  ApiKey  $apiKey  API Key
     * @param  string  $scope  Scope a verificar
     */
    public function hasScope(ApiKey $apiKey, string $scope): bool
    {
        return $apiKey->hasScope($scope);
    }

    /**
     * Validar formato de scopes
     *
     * @param  list<string>  $scopes  Array de scopes
     *
     * @throws BusinessLogicException Si algún scope no es válido
     */
    protected function validateScopes(array $scopes): void
    {
        $availableScopes = config('api-keys.available_scopes', []);

        foreach ($scopes as $scope) {
            // Permitir wildcard
            if ($scope === '*') {
                continue;
            }

            // Validar formato resource.action
            if (! preg_match('/^[a-z0-9_-]+\.[a-z0-9_-]+$/', $scope)) {
                throw BusinessLogicException::ruleViolation("El scope '{$scope}' no tiene un formato válido. Debe ser 'resource.action'");
            }

            // Validar contra lista de scopes disponibles si está configurada
            if (! empty($availableScopes) && ! in_array($scope, $availableScopes)) {
                throw BusinessLogicException::ruleViolation("El scope '{$scope}' no está disponible");
            }
        }
    }

    /**
     * Las keys `test` solo se aceptan fuera de producción (o con api-keys.allow_test_in_production).
     */
    protected function testKeysAllowed(): bool
    {
        return ! app()->isProduction() || (bool) config('api-keys.allow_test_in_production', false);
    }

    /**
     * Limpiar cache de una key específica
     */
    protected function clearKeyCache(string $hashedKey): void
    {
        $cacheKey = self::CACHE_PREFIX.$hashedKey;
        // Misma clave global con la que la guarda validate(), aunque se revoque desde un tenant.
        app(TenantContext::class)->withoutTenant(fn () => CacheService::forget($cacheKey));
    }

    /**
     * Limpiar cache de un usuario
     */
    protected function clearUserCache(int|string $userId): void
    {
        CacheService::forgetTag("user:{$userId}:api_keys");
    }
}
