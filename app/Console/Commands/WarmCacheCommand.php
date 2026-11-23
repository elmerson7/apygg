<?php

namespace App\Console\Commands;

use App\Contracts\PermissionServiceInterface;
use App\Contracts\RoleServiceInterface;
use App\Enums\WebhookStatusEnum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Services\CacheService;
use App\Services\LogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class WarmCacheCommand extends Command
{
    public function __construct(
        private readonly RoleServiceInterface $roleService,
        private readonly PermissionServiceInterface $permissionService,
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warm {--force : Forzar warming incluso si el cache ya existe}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-calentar el cache con datos frecuentemente accedidos';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Iniciando cache warming...');

        $startTime = microtime(true);
        $warmed = 0;

        try {
            // 1. Pre-cargar roles y permisos (datos críticos del sistema RBAC)
            $this->info('Pre-cargando roles y permisos...');
            $warmed += $this->warmRolesAndPermissions();

            // 2. Pre-cargar configuraciones del sistema
            $this->info('Pre-cargando configuraciones del sistema...');
            $warmed += $this->warmSystemConfig();

            // 3. Pre-cargar datos de usuarios recientes (opcional, solo si hay usuarios)
            $this->info('Pre-cargando datos de usuarios recientes...');
            $warmed += $this->warmActiveUsers();

            // 4. Pre-cargar configuración de webhooks activos
            $this->info('Pre-cargando configuración de webhooks...');
            $warmed += $this->warmWebhooks();

            // 5. Pre-cargar índices de búsqueda (Meilisearch)
            $this->info('Pre-cargando índices de búsqueda...');
            $warmed += $this->warmSearchIndexes();

            $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✓ Cache warming completado: {$warmed} elementos cacheados en {$elapsedTime}ms");
            LogService::info('Cache warming completado exitosamente', [
                'items_warmed' => $warmed,
                'elapsed_time_ms' => $elapsedTime,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error durante cache warming: '.$e->getMessage());
            LogService::error('Error en cache warming', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Pre-cargar roles y permisos en cache
     */
    protected function warmRolesAndPermissions(): int
    {
        $count = 0;

        try {
            $roleService = $this->roleService;
            $permissionService = $this->permissionService;

            // Pre-cargar todos los roles usando el servicio (respeta su lógica de cache)
            $roles = Role::all();
            foreach ($roles as $role) {
                $roleService->find((string) $role->getKey());
                $count++;
            }

            // Pre-cargar lista completa de roles
            $roleService->all();
            $count++;

            // Pre-cargar roles por nombre (solo los principales)
            $mainRoles = ['admin', 'manager', 'user'];
            foreach ($mainRoles as $roleName) {
                $roleService->findByName($roleName);
                $count++;
            }

            // Pre-cargar todos los permisos usando el servicio
            $permissions = Permission::all();
            foreach ($permissions as $permission) {
                $permissionService->find((string) $permission->getKey());
                $count++;
            }

            // Pre-cargar permisos por recurso usando el servicio
            $resources = Permission::distinct()->pluck('resource');
            foreach ($resources as $resource) {
                $permissionService->getByResource($resource);
                $count++;
            }

            $this->line("  ✓ {$count} elementos de roles y permisos cacheados");

            return $count;
        } catch (\Exception $e) {
            $this->warn("  ⚠ Error cacheando roles y permisos: {$e->getMessage()}");

            return $count;
        }
    }

    /**
     * Pre-cargar configuraciones del sistema
     */
    protected function warmSystemConfig(): int
    {
        $count = 0;

        try {
            // Pre-cargar feature flags
            if (config('features')) {
                CacheService::set('config:features', config('features'), 7200);
                $count++;
            }

            // Pre-cargar configuración de API keys
            if (config('api-keys')) {
                CacheService::set('config:api-keys', config('api-keys'), 7200);
                $count++;
            }

            // Pre-cargar configuración de rate limiting
            if (config('rate-limiting')) {
                CacheService::set('config:rate-limiting', config('rate-limiting'), 7200);
                $count++;
            }

            $this->line("  ✓ {$count} configuraciones del sistema cacheadas");

            return $count;
        } catch (\Exception $e) {
            $this->warn("  ⚠ Error cacheando configuraciones: {$e->getMessage()}");

            return $count;
        }
    }

    /**
     * Pre-cargar datos de usuarios recientes (solo los primeros 50 para no sobrecargar)
     */
    protected function warmActiveUsers(): int
    {
        $count = 0;

        try {
            // Pre-cargar usuarios recientes (no eliminados, ordenados por fecha de creación)
            // Limitamos a 50 para no sobrecargar el sistema
            $users = User::orderBy('created_at', 'desc')
                ->limit(50)
                ->get();

            foreach ($users as $user) {
                // Pre-cargar datos básicos del usuario con relaciones
                CacheService::rememberUser($user->id, function () use ($user) {
                    return $user->load(['roles', 'permissions']);
                });
                $count++;
            }

            if ($count > 0) {
                $this->line("  ✓ {$count} usuarios recientes cacheados");
            } else {
                $this->line('  ℹ No hay usuarios para cachear');
            }

            return $count;
        } catch (\Exception $e) {
            $this->warn("  ⚠ Error cacheando usuarios: {$e->getMessage()}");

            return $count;
        }
    }

    /**
     * Pre-cargar configuración de webhooks activos
     */
    protected function warmWebhooks(): int
    {
        $count = 0;

        try {
            if (class_exists(Webhook::class)) {
                // Multiempresa: la caché de cada tenant lleva su prefijo; se calienta tenant por tenant
                $warm = function () use (&$count): void {
                    foreach (Webhook::where('status', WebhookStatusEnum::active->value)->get() as $webhook) {
                        CacheService::set("webhook:{$webhook->id}", [
                            'id' => $webhook->id,
                            'name' => $webhook->name,
                            'url' => $webhook->url,
                            'events' => $webhook->events,
                            'status' => $webhook->status,
                        ], 3600);
                        $count++;
                    }
                };

                $context = app(TenantContext::class);

                if ($context->enabled()) {
                    Tenant::query()->where('status', 'active')->each(fn (Tenant $tenant) => $context->run($tenant, $warm));
                } else {
                    $warm();
                }

                if ($count >= 1) {
                    $this->line("  ✓ {$count} webhooks activos cacheados");
                } else {
                    $this->line('  ℹ No hay webhooks activos para cachear');
                }
            }

            return $count;
        } catch (\Exception $e) {
            $this->warn("  ⚠ Error cacheando webhooks: {$e->getMessage()}");

            return $count;
        }
    }

    /**
     * Pre-cargar información de índices de búsqueda
     */
    protected function warmSearchIndexes(): int
    {
        $count = 0;

        try {
            // Verificar que Meilisearch esté configurado
            if (config('scout.driver') !== 'meilisearch') {
                $this->line('  ℹ Meilisearch no está configurado, saltando índices');

                return $count;
            }

            // Cachear información de índices disponibles
            $indexes = ['users']; // Agregar más según modelos searchable

            foreach ($indexes as $index) {
                CacheService::set("search:index:{$index}:info", [
                    'name' => $index,
                    'status' => 'ready',
                    'last_synced' => now()->toIso8601String(),
                ], 1800); // 30 minutos
                $count++;
            }

            $this->line("  ✓ {$count} índices de búsqueda cacheados");

            return $count;
        } catch (\Exception $e) {
            $this->warn("  ⚠ Error cacheando índices de búsqueda: {$e->getMessage()}");

            return $count;
        }
    }
}
