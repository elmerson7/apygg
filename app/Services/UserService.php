<?php

namespace App\Services;

use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Events\PermissionGranted;
use App\Events\PermissionRevoked;
use App\Events\RoleAssigned;
use App\Events\RoleRemoved;
use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Events\UserRestored;
use App\Events\UserUpdated;
use App\Exceptions\BusinessLogicException;
use App\Models\Logs\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * UserService
 *
 * Servicio para gestión de usuarios:
 * - CRUD completo de usuarios
 * - Gestión de roles y permisos
 * - Búsqueda con filtros avanzados
 */
class UserService implements UserServiceInterface
{
    public function __construct(private readonly EmailVerificationServiceInterface $emailVerification) {}

    // Solo para borrar entradas antiguas: find() ya no cachea el usuario.
    protected const CACHE_PREFIX = 'user:';

    /**
     * Crear un nuevo usuario
     *
     * @throws BusinessLogicException Si el email ya existe
     */
    public function create(array $data, ?array $roleIds = null): User
    {
        // El email es único sin distinguir mayúsculas: `Admin@x.com` y `admin@x.com` son la misma cuenta.
        $data['email'] = mb_strtolower(trim($data['email']));

        if (User::where('email', $data['email'])->exists()) {
            throw BusinessLogicException::ruleViolation("El email '{$data['email']}' ya está en uso", 'EMAIL_IN_USE');
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Generar name desde first_name y last_name, y separarlos para profile
        $profileData = [];
        if (isset($data['first_name'])) {
            $profileData['first_name'] = $data['first_name'];
            unset($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $profileData['last_name'] = $data['last_name'];
            unset($data['last_name']);
        }

        if (isset($profileData['first_name']) && isset($profileData['last_name'])) {
            $data['name'] = trim($profileData['first_name'].' '.$profileData['last_name']);
        } elseif (isset($profileData['first_name'])) {
            $data['name'] = $profileData['first_name'];
        }

        if (! isset($data['state_id'])) {
            $data['state_id'] = 1;
        }

        // Usuario, perfil y roles se crean juntos o no se crea nada.
        $user = DB::transaction(function () use ($data, $profileData, $roleIds) {
            $user = User::create($data);

            // Todo usuario tiene perfil (aunque sea vacío): /user/profile lo asume.
            $user->profile()->create($profileData);

            if (! empty($roleIds)) {
                $user->syncRoles(array_map('intval', $roleIds));
            } else {
                $this->assignDefaultRole($user);
            }

            return $user;
        });

        $this->clearCache();
        event(new UserCreated($user));

        return $user->fresh(['roles', 'permissions', 'profile']) ?? $user;
    }

    /**
     * Asignar el rol por defecto (`config('auth.default_role')`).
     *
     * Un usuario sin rol no tiene ningún permiso, lo que se manifiesta como
     * 403 inexplicables en cuanto intenta hacer algo.
     */
    protected function assignDefaultRole(User $user): void
    {
        $roleName = config('auth.default_role');

        if (! $roleName) {
            return;
        }

        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $user->assignRole($role);
        }
    }

    /**
     * Actualizar un usuario existente.
     *
     * @throws ModelNotFoundException
     * @throws BusinessLogicException Si el email ya existe
     */
    public function update(string $userId, array $data, ?array $roleIds = null): User
    {
        $user = $this->find($userId);

        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (User::where('email', $data['email'])->where('id', '!=', $userId)->exists()) {
                throw BusinessLogicException::ruleViolation("El email '{$data['email']}' ya está en uso", 'EMAIL_IN_USE');
            }
        }

        $emailCambiado = isset($data['email']) && $data['email'] !== $user->email;
        $passwordCambiada = isset($data['password']);
        $emailAnterior = $user->email;

        $oldAttributes = $user->getAttributes();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        unset($data['role_ids']);

        // Generar name desde first_name y last_name si se enviaron
        $profileData = [];
        if (isset($data['first_name'])) {
            $profileData['first_name'] = $data['first_name'];
            unset($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $profileData['last_name'] = $data['last_name'];
            unset($data['last_name']);
        }

        if (isset($profileData['first_name']) && isset($profileData['last_name'])) {
            $data['name'] = trim($profileData['first_name'].' '.$profileData['last_name']);
        } elseif (isset($profileData['first_name'])) {
            $data['name'] = $profileData['first_name'];
        }

        $user->update($data);

        // Cambiar credenciales (email o contraseña) cierra todas las sesiones: quien las tenía abiertas
        // (p. ej. alguien que tomó la cuenta) no sigue dentro. Los enlaces de restablecimiento pedidos con
        // el email anterior dejan de valer.
        if ($emailCambiado || $passwordCambiada) {
            $user->revokeAllTokens();
        }

        if ($emailCambiado) {
            DB::table('password_reset_tokens')->where('email', $emailAnterior)->delete();
        }

        // Un correo nuevo no está verificado: se anula y se vuelve a pedir la verificación.
        if ($emailCambiado) {
            $user->forceFill(['email_verified_at' => null])->save();

            if (config('auth.email_verification.send_on_email_change', true)) {
                $this->emailVerification->send($user);
            }
        }

        // Actualizar profile si se enviaron first_name o last_name
        if (! empty($profileData)) {
            if ($user->profile) {
                $user->profile->update($profileData);
            } else {
                $user->profile()->create($profileData);
            }
        }

        if ($roleIds !== null) {
            $this->syncUserRoles($user, $roleIds);
        }

        $this->clearCache($userId);
        event(new UserUpdated($user, $oldAttributes));

        return $user->fresh(['roles', 'permissions', 'profile']) ?? $user;
    }

    /**
     * Actualizar el perfil del propio usuario.
     *
     * Solo campos de perfil: `name`, `username` y `timezone` (tabla users) y los
     * de `user_profiles`. Email, contraseña y roles no se tocan aquí.
     *
     * @param  array<string, mixed>  $data  Datos ya validados
     */
    public function updateProfile(User $user, array $data): User
    {
        $oldAttributes = $user->getAttributes();

        $accountData = array_intersect_key($data, array_flip(['name', 'username', 'timezone', 'locale']));
        $profileData = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'phone', 'bio', 'date_of_birth', 'gender',
            'address', 'city', 'state', 'country', 'postal_code', 'social_links',
        ]));

        DB::transaction(function () use ($user, $accountData, $profileData) {
            $profile = $user->profile ?? $user->profile()->create([]);

            // Sin `name` explícito, se deriva de nombre y apellidos
            if (! isset($accountData['name']) && (isset($profileData['first_name']) || isset($profileData['last_name']))) {
                $first = $profileData['first_name'] ?? $profile->first_name;
                $last = $profileData['last_name'] ?? $profile->last_name;
                $derived = trim(($first ?? '').' '.($last ?? ''));
                if ($derived !== '') {
                    $accountData['name'] = $derived;
                }
            }

            if ($accountData !== []) {
                $user->update($accountData);
            }
            if ($profileData !== []) {
                $profile->update($profileData);
            }
        });

        $this->clearCache((string) $user->id);
        event(new UserUpdated($user, $oldAttributes));

        return $user->fresh(['profile']) ?? $user;
    }

    /**
     * Sync roles for a user, firing appropriate events for added/removed roles.
     *
     * @param  list<int|string>  $roleIds
     */
    protected function syncUserRoles(User $user, array $roleIds): void
    {
        $existingRoles = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoles = array_diff($roleIds, $existingRoles);
        if (! empty($invalidRoles)) {
            throw BusinessLogicException::ruleViolation('Los siguientes roles no existen: '.implode(', ', $invalidRoles), 'ROLES_NOT_FOUND');
        }

        $previousRoleIds = $user->roles()->select('roles.id')->pluck('id')->toArray();
        $user->syncRoles(array_map('intval', $roleIds));

        foreach (array_diff($roleIds, $previousRoleIds) as $roleId) {
            if ($role = Role::query()->find((int) $roleId)) {
                event(new RoleAssigned($user, $role));
            }
        }
        foreach (array_diff($previousRoleIds, $roleIds) as $roleId) {
            if ($role = Role::query()->find((int) $roleId)) {
                event(new RoleRemoved($user, $role));
            }
        }
    }

    /**
     * Actualizar preferencias del usuario (JSON en users.preferences).
     * Normaliza: sms, push, email en raíz se mueven a notifications.
     *
     * @throws ModelNotFoundException
     */
    public function updatePreferences(string $userId, array $preferences): User
    {
        $user = $this->find($userId);
        $normalized = $this->normalizePreferencesPayload($preferences);
        $existing = is_array($user->preferences) ? $user->preferences : [];
        $merged = array_replace_recursive($existing, $normalized);
        $user->update(['preferences' => $merged]);
        $this->clearCache($userId);

        return $user->fresh(['roles', 'permissions']) ?? $user;
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    protected function normalizePreferencesPayload(array $preferences): array
    {
        $notificationKeys = ['sms', 'push', 'email'];
        $atRoot = array_intersect_key($preferences, array_flip($notificationKeys));
        if ($atRoot === []) {
            return $preferences;
        }
        $out = $preferences;
        foreach ($notificationKeys as $key) {
            unset($out[$key]);
        }
        $out['notifications'] = array_replace_recursive($out['notifications'] ?? [], $atRoot);

        return $out;
    }

    /**
     * Eliminar un usuario (soft delete)
     *
     * @throws ModelNotFoundException
     */
    public function delete(string $userId): bool
    {
        $user = $this->find($userId);

        $deleted = DB::transaction(function () use ($user, $userId) {
            $this->ensureNotLastAdmin($user);

            return User::destroy($userId) > 0;
        });
        $this->clearCache($userId);
        event(new UserDeleted($user));

        return $deleted;
    }

    /**
     * Restaurar un usuario eliminado
     *
     * @throws ModelNotFoundException
     */
    public function restore(string $userId): User
    {
        $user = User::onlyTrashed()->findOrFail($userId);
        $user->restore();
        $this->clearCache($userId);
        event(new UserRestored($user));

        return $user->fresh(['roles', 'permissions']) ?? $user;
    }

    /**
     * Buscar un usuario por ID
     *
     * @throws ModelNotFoundException
     */
    public function find(string $userId): User
    {
        // Sin caché: guardaba el modelo entero (con el hash de la contraseña) en Redis una hora y las policies
        // decidían con roles que podían estar desactualizados (p. ej. un admin recién degradado). Por id es barato.
        return User::with(['roles', 'permissions'])->findOrFail($userId);
    }

    /**
     * Listar usuarios con paginación y filtros.
     * Carga roles, state por defecto; permissions solo si se solicita en include.
     *
     * @param  array<string, mixed>  $filters  ['search', 'per_page', 'page', 'include', 'role', 'exclude_roles', 'sort', 'order'];
     *                                         `listing` (ListingQuery ya validado) sustituye a `sort`/`order`/`include` y añade los filtros por columna
     * @return LengthAwarePaginator<int, User>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max(1, (int) ($filters['per_page'] ?? 20)), 500);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $search = $filters['search'] ?? null;
        $include = $filters['include'] ?? '';
        $role = $filters['role'] ?? null;
        $excludeRoles = $filters['exclude_roles'] ?? null;
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc');
        $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';
        $sortableColumns = ['name', 'email', 'username', 'identity_document', 'created_at', 'updated_at', 'email_verified_at'];
        if (! in_array($sort, $sortableColumns)) {
            $sort = 'created_at';
        }

        $relations = ['roles', 'profile'];
        if ($include !== '') {
            $requested = array_map('trim', explode(',', $include));
            if (in_array('permissions', $requested, true)) {
                $relations[] = 'permissions';
            }
        }

        $listing = ($filters['listing'] ?? null) instanceof ListingQuery ? $filters['listing'] : null;
        $query = User::with($relations);

        if ($role && trim((string) $role) !== '') {
            $query->whereHas('roles', fn ($q) => $q->where('roles.name', trim($role)));
        }

        if ($excludeRoles && trim((string) $excludeRoles) !== '') {
            $rolesToExclude = array_map('trim', explode(',', $excludeRoles));
            $query->whereDoesntHave('roles', fn ($q) => $q->whereIn('roles.name', $rolesToExclude));
        }

        // Híbrido: Meilisearch (typo tolerance para nombre/email) + SQL ILIKE (substring para identity_document)
        if ($search && trim($search) !== '') {
            $searchTerm = trim($search);
            $searchPattern = '%'.$searchTerm.'%';

            $matchedIds = collect();
            $meilisearchAvailable = config('scout.driver') === 'meilisearch' && $this->meilisearchIsAvailable();

            if ($meilisearchAvailable) {
                // 1. Meilisearch: typo tolerance para name, email, username
                // `search()` lo aporta el módulo `search` (trait Searchable de Scout); solo se llega aquí si está activo.
                /** @var Collection<int, User> $found */
                $found = call_user_func([User::class, 'search'], $searchTerm)->get();
                $matchedIds = $found->pluck('id');

                // 2. SQL ILIKE: substring matching para identity_document
                //    (Meilisearch no soporta %substring%)
                $docIds = User::where('identity_document', 'ILIKE', $searchPattern)->pluck('id');

                $allIds = $matchedIds->merge($docIds)->unique()->values();

                if ($allIds->isNotEmpty()) {
                    $query->whereIn('id', $allIds);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } else {
                // Fallback: SQL ILIKE en todos los campos si Meilisearch no está disponible
                $query->where(fn ($q) => $q
                    ->where('name', 'ILIKE', $searchPattern)
                    ->orWhere('username', 'ILIKE', $searchPattern)
                    ->orWhere('email', 'ILIKE', $searchPattern)
                    ->orWhere('identity_document', 'ILIKE', $searchPattern)
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'ILIKE', $searchPattern))
                );
            }
        }

        if ($listing !== null) {
            // `search`, `role` y `exclude_roles` ya se aplicaron arriba (Meilisearch + ILIKE, relaciones).
            return $listing->apply($query, handled: ['search', 'role', 'exclude_roles'])->paginate($perPage, ['*'], 'page', $page);
        }

        return $query->orderBy($sort, $order)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Asignar roles a un usuario
     *
     * @throws ModelNotFoundException
     * @throws BusinessLogicException Si algún rol no existe
     */
    public function assignRoles(string $userId, array $roleIds): User
    {
        $user = $this->find($userId);

        $existingRoles = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoles = array_diff($roleIds, $existingRoles);
        if (! empty($invalidRoles)) {
            throw BusinessLogicException::ruleViolation('Los siguientes roles no existen: '.implode(', ', $invalidRoles), 'ROLES_NOT_FOUND');
        }

        return DB::transaction(function () use ($user, $userId, $roleIds) {
            $adminRoleId = Role::where('name', 'admin')->value('id');
            if ($adminRoleId !== null && ! in_array((int) $adminRoleId, array_map('intval', $roleIds), true)) {
                $this->ensureNotLastAdmin($user);
            }

            return $this->syncRolesAndFire($user, $userId, $roleIds);
        });
    }

    /**
     * Sincronizar roles y emitir los eventos de alta/baja.
     *
     * @param  list<int|string>  $roleIds
     */
    private function syncRolesAndFire(User $user, string $userId, array $roleIds): User
    {
        $previousRoleIds = $user->roles()->select('roles.id')->pluck('id')->toArray();
        $user->syncRoles(array_map('intval', $roleIds));
        $this->clearCache($userId);

        foreach (array_diff($roleIds, $previousRoleIds) as $roleId) {
            if ($role = Role::query()->find((int) $roleId)) {
                event(new RoleAssigned($user, $role));
            }
        }
        foreach (array_diff($previousRoleIds, $roleIds) as $roleId) {
            if ($role = Role::query()->find((int) $roleId)) {
                event(new RoleRemoved($user, $role));
            }
        }

        return $user->fresh(['roles', 'permissions']) ?? $user;
    }

    /**
     * Remover un rol de un usuario
     *
     * @throws ModelNotFoundException
     */
    public function removeRole(string $userId, string $roleId): User
    {
        $user = $this->find($userId);
        $role = Role::findOrFail($roleId);

        if ($user->roles()->count() === 1 && $user->hasRole('admin')) {
            throw BusinessLogicException::operationNotAllowed('No se puede remover el último rol de un administrador', 'remove_last_admin_role');
        }

        return DB::transaction(function () use ($user, $userId, $role) {
            if ($role->name === 'admin') {
                $this->ensureNotLastAdmin($user);
            }

            $user->removeRole($role);
            $this->clearCache($userId);
            event(new RoleRemoved($user, $role));

            return $user->fresh(['roles', 'permissions']) ?? $user;
        });
    }

    /**
     * Impedir que el sistema se quede sin administradores.
     *
     * Debe llamarse dentro de una transacción: bloquea las filas de los admins
     * para que dos peticiones simultáneas no quiten a los dos últimos.
     *
     * @throws BusinessLogicException Si $user es admin y no hay otro admin
     */
    protected function ensureNotLastAdmin(User $user): void
    {
        if (! $user->hasRole('admin')) {
            return;
        }

        $otherAdmins = User::whereHas('roles', fn ($q) => $q->where('roles.name', 'admin'))
            ->where('users.id', '!=', $user->id)
            ->lockForUpdate()
            ->pluck('users.id');

        if ($otherAdmins->isEmpty()) {
            throw new BusinessLogicException('No se puede dejar el sistema sin administradores', 'LAST_ADMIN');
        }
    }

    /**
     * Asignar permisos directos a un usuario
     *
     * @throws ModelNotFoundException
     */
    public function assignPermissions(string $userId, array $permissionIds): User
    {
        $user = $this->find($userId);
        $previousPermissionIds = $user->permissions()->select('permissions.id')->pluck('id')->toArray();
        $user->syncPermissions(array_map('intval', $permissionIds));
        $this->clearCache($userId);

        foreach (array_diff($permissionIds, $previousPermissionIds) as $permissionId) {
            if ($permission = Permission::query()->find((int) $permissionId)) {
                event(new PermissionGranted($user, $permission));
            }
        }
        foreach (array_diff($previousPermissionIds, $permissionIds) as $permissionId) {
            if ($permission = Permission::query()->find((int) $permissionId)) {
                event(new PermissionRevoked($user, $permission));
            }
        }

        return $user->fresh(['roles', 'permissions']) ?? $user;
    }

    /**
     * Remover un permiso directo de un usuario
     *
     * @throws ModelNotFoundException
     */
    public function removePermission(string $userId, string $permissionId): User
    {
        $user = $this->find($userId);
        $permission = Permission::findOrFail($permissionId);

        $user->revokePermissionTo($permission);
        $this->clearCache($userId);
        event(new PermissionRevoked($user, $permission));

        LogService::info('Permiso removido de usuario', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ], 'activity');

        return $user->fresh(['roles', 'permissions']) ?? $user;
    }

    /**
     * Obtener historial de actividad de un usuario
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     *
     * @throws ModelNotFoundException
     */
    public function getActivityLogs(string $userId, int $perPage = 20): LengthAwarePaginator
    {
        $user = $this->find($userId);

        return $user->activityLogs()->orderBy('created_at', 'desc')->paginate($perPage);
    }

    private function meilisearchIsAvailable(): bool
    {
        try {
            $host = config('scout.meilisearch.host');
            $ch = curl_init($host.'/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function clearCache(?string $userId = null): void
    {
        if ($userId) {
            CacheService::forget(self::CACHE_PREFIX.$userId);
        }
        CacheService::forget(self::CACHE_PREFIX.'list');
    }
}
