<?php

namespace App\Providers;

use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\BackupServiceInterface;
use App\Contracts\DocsIndexerInterface;
use App\Contracts\DocsServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\HostResolverInterface;
use App\Contracts\LocaleResolverInterface;
use App\Contracts\LocaleSettingsServiceInterface;
use App\Contracts\MailAccountServiceInterface;
use App\Contracts\MailEventStatusServiceInterface;
use App\Contracts\MailSendPolicyInterface;
use App\Contracts\MailServiceInterface;
use App\Contracts\MenuServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PasswordServiceInterface;
use App\Contracts\PermissionServiceInterface;
use App\Contracts\RoleServiceInterface;
use App\Contracts\SecurityAuditServiceInterface;
use App\Contracts\SecurityServiceInterface;
use App\Contracts\SequenceInterface;
use App\Contracts\SettingsServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Contracts\WebhookServiceInterface;
use App\Contracts\WebhookSignatureVerifierInterface;
use App\Services\ApiKeyService;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\Docs\DocsIndexer;
use App\Services\Docs\DocsService;
use App\Services\FileService;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\LocaleSettingsService;
use App\Services\Mail\MailAccountService;
use App\Services\Mail\MailEventStatusService;
use App\Services\Mail\MailSendPolicy;
use App\Services\Mail\SvixSignatureVerifier;
use App\Services\MailService;
use App\Services\Menu\MenuService;
use App\Services\NotificationService;
use App\Services\PasswordService;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\SecurityAuditService;
use App\Services\SecurityService;
use App\Services\SettingsService;
use App\Services\TokenService;
use App\Services\UserService;
use App\Services\Webhooks\DnsHostResolver;
use App\Services\WebhookService;
use App\Support\Sequences\Sequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

/**
 * ContractServiceProvider
 *
 * Enlaza cada interfaz de `app/Contracts` con su implementación. Controladores,
 * servicios, jobs, listeners, comandos y middlewares reciben la INTERFAZ por
 * constructor o método; el contenedor la resuelve aquí.
 *
 * Tipo de binding (Octane: el contenedor vive entre peticiones):
 * - `bind` (instancia nueva por resolución) para los servicios. Son baratos y
 *   así nunca arrastran estado de una petición a la siguiente.
 * - `singleton` solo para colaboradores sin estado y costosos de crear
 *   (`HostResolverInterface`).
 * - Nada con estado de petición debe ir en `singleton`: usa `scoped`.
 *
 * Sin interfaz (a propósito):
 * - `LogService` y `CacheService`: fachadas estáticas de uso global (>200
 *   llamadas, también desde middlewares y el handler de excepciones).
 * - `LlmGateway`: constructor fluido con estado por llamada
 *   (`useCase()->module()->chat()`); se resuelve con `bind` por clase.
 * - `Logging\*Logger`: fachadas estáticas de registro.
 */
class ContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);
        $this->app->bind(TokenServiceInterface::class, TokenService::class);
        $this->app->bind(ApiKeyServiceInterface::class, ApiKeyService::class);
        $this->app->bind(WebhookServiceInterface::class, WebhookService::class);
        $this->app->bind(FileServiceInterface::class, FileService::class);
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
        $this->app->bind(SecurityServiceInterface::class, SecurityService::class);
        $this->app->bind(PermissionServiceInterface::class, PermissionService::class);
        $this->app->bind(RoleServiceInterface::class, RoleService::class);
        $this->app->bind(SettingsServiceInterface::class, SettingsService::class);
        $this->app->bind(PasswordServiceInterface::class, PasswordService::class);
        $this->app->bind(MailServiceInterface::class, MailService::class);
        $this->app->bind(BackupServiceInterface::class, BackupService::class);
        $this->app->bind(MenuServiceInterface::class, MenuService::class);
        $this->app->bind(DocsServiceInterface::class, DocsService::class);
        $this->app->bind(DocsIndexerInterface::class, DocsIndexer::class);
        $this->app->bind(MailAccountServiceInterface::class, MailAccountService::class);
        $this->app->bind(MailEventStatusServiceInterface::class, MailEventStatusService::class);
        $this->app->bind(MailSendPolicyInterface::class, MailSendPolicy::class);
        $this->app->bind(WebhookSignatureVerifierInterface::class, SvixSignatureVerifier::class);
        $this->app->bind(SecurityAuditServiceInterface::class, SecurityAuditService::class);
        $this->app->bind(SequenceInterface::class, Sequence::class);

        $this->app->bind(LocaleResolverInterface::class, LocaleResolver::class);
        $this->app->bind(LocaleSettingsServiceInterface::class, LocaleSettingsService::class);

        // Colaborador sin estado: el DNS real; los tests lo sustituyen con `$this->app->instance(...)`.
        $this->app->singleton(HostResolverInterface::class, DnsHostResolver::class);

        // Estado POR PETICIÓN/JOB (tenant activo): scoped, nunca singleton ni estático (docs/multi-tenancy.md).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
