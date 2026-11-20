<?php

namespace App\Providers;

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\AuditServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\BackupServiceInterface;
use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\BrandResolverInterface;
use App\Contracts\ConsentServiceInterface;
use App\Contracts\DeviceServiceInterface;
use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\DocsIndexerInterface;
use App\Contracts\DocsServiceInterface;
use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\ExportServiceInterface;
use App\Contracts\FeatureFlagServiceInterface;
use App\Contracts\FileServiceInterface;
use App\Contracts\HostResolverInterface;
use App\Contracts\IdempotencyInterface;
use App\Contracts\ImpersonationServiceInterface;
use App\Contracts\ImportServiceInterface;
use App\Contracts\InboundWebhookServiceInterface;
use App\Contracts\InboxServiceInterface;
use App\Contracts\InvoiceServiceInterface;
use App\Contracts\LocaleResolverInterface;
use App\Contracts\LocaleSettingsServiceInterface;
use App\Contracts\MailAccountServiceInterface;
use App\Contracts\MailEventStatusServiceInterface;
use App\Contracts\MailSendPolicyInterface;
use App\Contracts\MailServiceInterface;
use App\Contracts\MailSuppressionServiceInterface;
use App\Contracts\MaintenanceServiceInterface;
use App\Contracts\MenuServiceInterface;
use App\Contracts\NotificationDeliveryServiceInterface;
use App\Contracts\NotificationPreferenceServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\PasswordServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PdfRendererInterface;
use App\Contracts\PermissionServiceInterface;
use App\Contracts\PersonalDataExportServiceInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\Privacy\DeletionSecondFactorInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\RoleServiceInterface;
use App\Contracts\SecurityAuditServiceInterface;
use App\Contracts\SecurityServiceInterface;
use App\Contracts\SequenceInterface;
use App\Contracts\SessionServiceInterface;
use App\Contracts\SettingsRegistryInterface;
use App\Contracts\SettingsServiceInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Contracts\SubscriptionServiceInterface;
use App\Contracts\TenantAdminServiceInterface;
use App\Contracts\TenantInvitationServiceInterface;
use App\Contracts\TenantMemberServiceInterface;
use App\Contracts\TenantOnboardingServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\TwoFactorServiceInterface;
use App\Contracts\UserServiceInterface;
use App\Contracts\WebhookServiceInterface;
use App\Contracts\WebhookSignatureVerifierInterface;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\Billing\BillingAccountService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentProcessor;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\RenewalService;
use App\Services\Billing\SubscriptionLifecycle;
use App\Services\Billing\SubscriptionService;
use App\Services\DirectUploadService;
use App\Services\Docs\DocsIndexer;
use App\Services\Docs\DocsService;
use App\Services\EmailVerificationService;
use App\Services\ExportService;
use App\Services\FeatureFlagService;
use App\Services\FileService;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\LocaleSettingsService;
use App\Services\IdempotencyService;
use App\Services\ImpersonationService;
use App\Services\ImportService;
use App\Services\Mail\BrandResolver;
use App\Services\Mail\MailAccountService;
use App\Services\Mail\MailEventStatusService;
use App\Services\Mail\MailSendPolicy;
use App\Services\Mail\MailSuppressionService;
use App\Services\Mail\SvixSignatureVerifier;
use App\Services\MailService;
use App\Services\MaintenanceService;
use App\Services\Menu\MenuService;
use App\Services\Notifications\DeviceService;
use App\Services\Notifications\InboxService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\PasswordService;
use App\Services\Pdf\DompdfRenderer;
use App\Services\PermissionService;
use App\Services\PlanLimits;
use App\Services\Privacy\AccountDeletionService;
use App\Services\Privacy\ConsentService;
use App\Services\Privacy\PersonalDataExportService;
use App\Services\RoleService;
use App\Services\SecurityAuditService;
use App\Services\SecurityService;
use App\Services\SessionService;
use App\Services\SettingsRegistry;
use App\Services\SettingsService;
use App\Services\TenantAdminService;
use App\Services\TenantInvitationService;
use App\Services\TenantMemberService;
use App\Services\TenantOnboardingService;
use App\Services\TokenService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use App\Services\Webhooks\DnsHostResolver;
use App\Services\Webhooks\InboundWebhookService;
use App\Services\WebhookService;
use App\Support\Privacy\Contributors\ApiKeysContributor;
use App\Support\Privacy\Contributors\AuditContributor;
use App\Support\Privacy\Contributors\ConsentsContributor;
use App\Support\Privacy\Contributors\FilesContributor;
use App\Support\Privacy\Contributors\NotificationsContributor;
use App\Support\Privacy\Contributors\ProfileContributor;
use App\Support\Privacy\Contributors\SessionsContributor;
use App\Support\Privacy\Contributors\TenantMembershipsContributor;
use App\Support\Privacy\PersonalDataRegistry;
use App\Support\Privacy\TwoFactorDeletionSecondFactor;
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
        $this->app->bind(SessionServiceInterface::class, SessionService::class);
        $this->app->bind(TwoFactorServiceInterface::class, TwoFactorService::class);
        $this->app->bind(EmailVerificationServiceInterface::class, EmailVerificationService::class);
        $this->app->bind(ApiKeyServiceInterface::class, ApiKeyService::class);
        $this->app->bind(WebhookServiceInterface::class, WebhookService::class);
        $this->app->bind(FileServiceInterface::class, FileService::class);
        $this->app->bind(DirectUploadServiceInterface::class, DirectUploadService::class);
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
        $this->app->bind(SecurityServiceInterface::class, SecurityService::class);
        $this->app->bind(PermissionServiceInterface::class, PermissionService::class);
        $this->app->bind(RoleServiceInterface::class, RoleService::class);
        $this->app->bind(SettingsServiceInterface::class, SettingsService::class);
        $this->app->bind(SettingsRegistryInterface::class, SettingsRegistry::class);
        $this->app->bind(FeatureFlagServiceInterface::class, FeatureFlagService::class);
        $this->app->bind(PlanLimitsInterface::class, PlanLimits::class);
        $this->app->bind(PasswordServiceInterface::class, PasswordService::class);
        $this->app->bind(MailServiceInterface::class, MailService::class);
        $this->app->bind(BackupServiceInterface::class, BackupService::class);
        $this->app->bind(MenuServiceInterface::class, MenuService::class);
        $this->app->bind(DocsServiceInterface::class, DocsService::class);
        $this->app->bind(DocsIndexerInterface::class, DocsIndexer::class);
        $this->app->bind(MailAccountServiceInterface::class, MailAccountService::class);
        $this->app->bind(MailEventStatusServiceInterface::class, MailEventStatusService::class);
        $this->app->bind(MailSendPolicyInterface::class, MailSendPolicy::class);
        $this->app->bind(MailSuppressionServiceInterface::class, MailSuppressionService::class);
        $this->app->bind(BrandResolverInterface::class, BrandResolver::class);
        $this->app->bind(WebhookSignatureVerifierInterface::class, SvixSignatureVerifier::class);
        $this->app->bind(InboundWebhookServiceInterface::class, InboundWebhookService::class);
        $this->app->bind(SecurityAuditServiceInterface::class, SecurityAuditService::class);
        $this->app->bind(SequenceInterface::class, Sequence::class);
        $this->app->bind(IdempotencyInterface::class, IdempotencyService::class);

        // Facturación y suscripciones (docs/billing.md)
        $this->app->bind(PlanCatalogInterface::class, PlanCatalog::class);
        $this->app->bind(BillingAccountServiceInterface::class, BillingAccountService::class);
        $this->app->bind(SubscriptionLifecycleInterface::class, SubscriptionLifecycle::class);
        $this->app->bind(InvoiceServiceInterface::class, InvoiceService::class);
        $this->app->bind(PaymentProcessorInterface::class, PaymentProcessor::class);
        $this->app->bind(SubscriptionServiceInterface::class, SubscriptionService::class);
        $this->app->bind(RenewalServiceInterface::class, RenewalService::class);

        $this->app->bind(LocaleResolverInterface::class, LocaleResolver::class);
        $this->app->bind(LocaleSettingsServiceInterface::class, LocaleSettingsService::class);
        $this->app->bind(TenantOnboardingServiceInterface::class, TenantOnboardingService::class);
        $this->app->bind(TenantInvitationServiceInterface::class, TenantInvitationService::class);
        $this->app->bind(TenantMemberServiceInterface::class, TenantMemberService::class);
        $this->app->bind(TenantAdminServiceInterface::class, TenantAdminService::class);
        $this->app->bind(AuditServiceInterface::class, AuditService::class);
        $this->app->bind(ImpersonationServiceInterface::class, ImpersonationService::class);
        $this->app->bind(MaintenanceServiceInterface::class, MaintenanceService::class);

        // Notificaciones multicanal (docs/notifications.md)
        $this->app->bind(InboxServiceInterface::class, InboxService::class);
        $this->app->bind(DeviceServiceInterface::class, DeviceService::class);
        $this->app->bind(NotificationPreferenceServiceInterface::class, NotificationPreferenceService::class);
        $this->app->bind(NotificationDeliveryServiceInterface::class, NotificationDeliveryService::class);
        $this->app->bind(ExportServiceInterface::class, ExportService::class);
        $this->app->bind(ImportServiceInterface::class, ImportService::class);
        $this->app->bind(PdfRendererInterface::class, DompdfRenderer::class);

        // Colaborador sin estado: el DNS real; los tests lo sustituyen con `$this->app->instance(...)`.
        // Privacidad (docs/privacy.md): cada módulo añade su contribuidor con la etiqueta `privacy.contributors`.
        $this->app->bind(ConsentServiceInterface::class, ConsentService::class);
        $this->app->bind(PersonalDataExportServiceInterface::class, PersonalDataExportService::class);
        $this->app->bind(AccountDeletionServiceInterface::class, AccountDeletionService::class);
        $this->app->bind(DeletionSecondFactorInterface::class, TwoFactorDeletionSecondFactor::class);
        $this->app->tag([
            FilesContributor::class, ApiKeysContributor::class, NotificationsContributor::class, ConsentsContributor::class,
            AuditContributor::class, SessionsContributor::class, TenantMembershipsContributor::class, ProfileContributor::class,
        ], 'privacy.contributors');
        $this->app->bind(PersonalDataRegistry::class, fn ($app) => new PersonalDataRegistry($app->tagged('privacy.contributors')));

        $this->app->singleton(HostResolverInterface::class, DnsHostResolver::class);

        // Estado POR PETICIÓN/JOB (tenant activo): scoped, nunca singleton ni estático (docs/multi-tenancy.md).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
