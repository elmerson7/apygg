<?php

namespace App\Providers;

use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\CacheServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\LogServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PermissionServiceInterface;
use App\Contracts\RoleServiceInterface;
use App\Contracts\SecurityServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Contracts\WebhookServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Contracts\RoleRepositoryInterface;
use App\Contracts\PermissionRepositoryInterface;
use App\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ApiKeyRepository;
use App\Services\ApiKeyService;
use App\Services\AuthService;
use App\Services\CacheService;
use App\Services\FileService;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\SecurityService;
use App\Services\TokenService;
use App\Services\UserService;
use App\Services\WebhookService;
use Illuminate\Support\ServiceProvider;

/**
 * ContractServiceProvider
 *
 * Service provider for binding interfaces to implementations.
 */
class ContractServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        // Auth
        $this->app->bind(AuthServiceInterface::class, AuthService::class);

        // User
        $this->app->bind(UserServiceInterface::class, UserService::class);

        // Token
        $this->app->bind(TokenServiceInterface::class, TokenService::class);

        // ApiKey
        $this->app->bind(ApiKeyServiceInterface::class, ApiKeyService::class);

        // Webhook
        $this->app->bind(WebhookServiceInterface::class, WebhookService::class);

        // File
        $this->app->bind(FileServiceInterface::class, FileService::class);

        // Cache
        $this->app->bind(CacheServiceInterface::class, CacheService::class);

        // Log
        $this->app->bind(LogServiceInterface::class, LogService::class);

        // Notification
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);

        // Security
        $this->app->bind(SecurityServiceInterface::class, SecurityService::class);

        // Permission
        $this->app->bind(PermissionServiceInterface::class, PermissionService::class);

        // Role
        $this->app->bind(RoleServiceInterface::class, RoleService::class);

        // Repositories
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->bind(ApiKeyRepositoryInterface::class, ApiKeyRepository::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}