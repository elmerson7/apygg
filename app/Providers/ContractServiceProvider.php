<?php

namespace App\Providers;

use App\Contracts\AccountDeletionServiceInterface;
use App\Contracts\ApiKeyServiceInterface;
use App\Contracts\AuditServiceInterface;
use App\Contracts\AuthServiceInterface;
use App\Contracts\BackupServiceInterface;
use App\Contracts\BillingAccountServiceInterface;
use App\Contracts\BillingNoticeServiceInterface;
use App\Contracts\BrandResolverInterface;
use App\Contracts\ConsentServiceInterface;
use App\Contracts\DeviceServiceInterface;
use App\Contracts\DirectUploadServiceInterface;
use App\Contracts\DocsIndexerInterface;
use App\Contracts\DocsServiceInterface;
use App\Contracts\EmailVerificationServiceInterface;
use App\Contracts\ExportServiceInterface;
use App\Contracts\FeatureFlagServiceInterface;
use App\Contracts\FileScanServiceInterface;
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
use App\Contracts\MalwareScannerInterface;
use App\Contracts\MenuServiceInterface;
use App\Contracts\MetricsServiceInterface;
use App\Contracts\MultipartStorageInterface;
use App\Contracts\NotificationDeliveryServiceInterface;
use App\Contracts\NotificationPreferenceServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\OfflineSyncInterface;
use App\Contracts\OptimisticLockInterface;
use App\Contracts\OtpLoginServiceInterface;
use App\Contracts\PasswordServiceInterface;
use App\Contracts\PaymentDiscrepancyServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\PaymentReconcilerInterface;
use App\Contracts\PdfRendererInterface;
use App\Contracts\PermissionServiceInterface;
use App\Contracts\PersonalDataExportServiceInterface;
use App\Contracts\PlanCatalogInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\Privacy\DeletionSecondFactorInterface;
use App\Contracts\RenewalServiceInterface;
use App\Contracts\RoleServiceInterface;
use App\Contracts\SecurityAnalysisProviderInterface;
use App\Contracts\SecurityAuditServiceInterface;
use App\Contracts\SecurityServiceInterface;
use App\Contracts\SequenceInterface;
use App\Contracts\SessionServiceInterface;
use App\Contracts\SettingsRegistryInterface;
use App\Contracts\SettingsServiceInterface;
use App\Contracts\SocialIdentityResolverInterface;
use App\Contracts\SocialLoginServiceInterface;
use App\Contracts\SubscriptionAccessInterface;
use App\Contracts\SubscriptionLifecycleInterface;
use App\Contracts\SubscriptionPlanSourceInterface;
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
use App\Services\Billing\BillingNoticeService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentDiscrepancyService;
use App\Services\Billing\PaymentProcessor;
use App\Services\Billing\PaymentReconciler;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\RenewalService;
use App\Services\Billing\SubscriptionAccess;
use App\Services\Billing\SubscriptionLifecycle;
use App\Services\Billing\SubscriptionPlanSource;
use App\Services\Billing\SubscriptionService;
use App\Services\DirectUploadService;
use App\Services\Docs\DocsIndexer;
use App\Services\Docs\DocsService;
use App\Services\EmailVerificationService;
use App\Services\ExportService;
use App\Services\FeatureFlagService;
use App\Services\Files\FileScanService;
use App\Services\Files\S3MultipartStorage;
use App\Services\FileService;
use App\Services\I18n\LocaleResolver;
use App\Services\I18n\LocaleSettingsService;
use App\Services\IdempotencyService;
use App\Services\ImpersonationService;
use App\Services\ImportService;
use App\Services\LLM\LlmSecurityAnalysisProvider;
use App\Services\Mail\BrandResolver;
use App\Services\Mail\MailAccountService;
use App\Services\Mail\MailEventStatusService;
use App\Services\Mail\MailSendPolicy;
use App\Services\Mail\MailSuppressionService;
use App\Services\Mail\SvixSignatureVerifier;
use App\Services\MailService;
use App\Services\MaintenanceService;
use App\Services\Menu\MenuService;
use App\Services\MetricsService;
use App\Services\Notifications\DeviceService;
use App\Services\Notifications\InboxService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\OfflineSync\OfflineSyncService;
use App\Services\OfflineSync\OptimisticLockService;
use App\Services\Otp\OtpLoginService;
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
use App\Services\Social\SocialLoginService;
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
use App\Support\Security\NullSecurityAnalysisProvider;
use App\Support\Sequences\Sequence;
use App\Support\Social\ProviderIdentityResolver;
use App\Support\Tenancy\NullSubscriptionAccess;
use App\Support\Tenancy\NullSubscriptionPlanSource;
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
        $this->app->bind(MultipartStorageInterface::class, S3MultipartStorage::class);
        // Antivirus: los motores salen de files.scanning.drivers en cada resolución (bind, sin estado entre peticiones).
        $this->app->bind(FileScanServiceInterface::class, fn ($app) => new FileScanService(
            array_map(function (string $driver) use ($app): MalwareScannerInterface {
                $class = config("files.scanning.engines.{$driver}");
                $scanner = is_string($class) ? $app->make($class) : null;

                if (! $scanner instanceof MalwareScannerInterface) {
                    throw new \InvalidArgumentException("Motor antivirus desconocido en FILE_SCAN_DRIVERS: '{$driver}'.");
                }

                return $scanner;
            }, (bool) config('files.scanning.enabled') ? array_values((array) config('files.scanning.drivers', [])) : []),
            $app->make(TenantContext::class),
        ));
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
        $this->app->bind(DocsServiceInterface::class, DocsService::class); // module:docs-assistant
        $this->app->bind(DocsIndexerInterface::class, DocsIndexer::class); // module:docs-assistant
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
        $this->app->bind(OfflineSyncInterface::class, OfflineSyncService::class); // module:offline-sync
        $this->app->bind(OptimisticLockInterface::class, OptimisticLockService::class); // module:offline-sync

        // Puntos de extensión con valor por defecto (sin módulo de facturación): el módulo `billing` los sustituye
        // con su propio `bind` más abajo (el último binding gana).
        $this->app->bind(SubscriptionAccessInterface::class, NullSubscriptionAccess::class);
        $this->app->bind(SubscriptionPlanSourceInterface::class, NullSubscriptionPlanSource::class);
        $this->app->bind(SecurityAnalysisProviderInterface::class, NullSecurityAnalysisProvider::class);

        $this->app->bind(SecurityAnalysisProviderInterface::class, LlmSecurityAnalysisProvider::class); // module:llm

        // Facturación y suscripciones (docs/billing.md)
        $this->app->bind(PlanCatalogInterface::class, PlanCatalog::class); // module:billing
        $this->app->bind(BillingAccountServiceInterface::class, BillingAccountService::class); // module:billing
        $this->app->bind(SubscriptionLifecycleInterface::class, SubscriptionLifecycle::class); // module:billing
        $this->app->bind(SubscriptionAccessInterface::class, SubscriptionAccess::class); // module:billing
        $this->app->bind(SubscriptionPlanSourceInterface::class, SubscriptionPlanSource::class); // module:billing
        $this->app->bind(InvoiceServiceInterface::class, InvoiceService::class); // module:billing
        $this->app->bind(PaymentProcessorInterface::class, PaymentProcessor::class); // module:billing
        $this->app->bind(PaymentReconcilerInterface::class, PaymentReconciler::class); // module:billing
        $this->app->bind(PaymentDiscrepancyServiceInterface::class, PaymentDiscrepancyService::class); // module:billing
        $this->app->bind(SubscriptionServiceInterface::class, SubscriptionService::class); // module:billing
        $this->app->bind(RenewalServiceInterface::class, RenewalService::class); // module:billing
        $this->app->bind(BillingNoticeServiceInterface::class, BillingNoticeService::class); // module:billing

        $this->app->bind(LocaleResolverInterface::class, LocaleResolver::class);
        $this->app->bind(LocaleSettingsServiceInterface::class, LocaleSettingsService::class);
        $this->app->bind(TenantOnboardingServiceInterface::class, TenantOnboardingService::class);
        $this->app->bind(TenantInvitationServiceInterface::class, TenantInvitationService::class);
        $this->app->bind(TenantMemberServiceInterface::class, TenantMemberService::class);
        $this->app->bind(TenantAdminServiceInterface::class, TenantAdminService::class);
        $this->app->bind(AuditServiceInterface::class, AuditService::class);
        $this->app->bind(ImpersonationServiceInterface::class, ImpersonationService::class); // module:impersonation
        $this->app->bind(MaintenanceServiceInterface::class, MaintenanceService::class); // module:maintenance
        $this->app->bind(MetricsServiceInterface::class, MetricsService::class); // module:metrics

        // Notificaciones multicanal (docs/notifications.md)
        $this->app->bind(InboxServiceInterface::class, InboxService::class);
        $this->app->bind(DeviceServiceInterface::class, DeviceService::class);
        $this->app->bind(NotificationPreferenceServiceInterface::class, NotificationPreferenceService::class);
        $this->app->bind(NotificationDeliveryServiceInterface::class, NotificationDeliveryService::class);
        $this->app->bind(ExportServiceInterface::class, ExportService::class); // module:export-import
        $this->app->bind(ImportServiceInterface::class, ImportService::class); // module:export-import
        $this->app->bind(PdfRendererInterface::class, DompdfRenderer::class);

        // Colaborador sin estado: el DNS real; los tests lo sustituyen con `$this->app->instance(...)`.
        // Privacidad (docs/privacy.md): cada módulo añade su contribuidor con la etiqueta `privacy.contributors`.
        $this->app->bind(ConsentServiceInterface::class, ConsentService::class); // module:privacy
        $this->app->bind(PersonalDataExportServiceInterface::class, PersonalDataExportService::class); // module:privacy
        $this->app->bind(AccountDeletionServiceInterface::class, AccountDeletionService::class); // module:privacy
        $this->app->bind(DeletionSecondFactorInterface::class, TwoFactorDeletionSecondFactor::class); // module:privacy
        // module:privacy:start
        $this->app->tag([
            FilesContributor::class, ApiKeysContributor::class, NotificationsContributor::class, ConsentsContributor::class,
            AuditContributor::class, SessionsContributor::class, TenantMembershipsContributor::class, ProfileContributor::class,
        ], 'privacy.contributors');
        // module:privacy:end
        $this->app->bind(PersonalDataRegistry::class, fn ($app) => new PersonalDataRegistry($app->tagged('privacy.contributors'))); // module:privacy

        // Login externo (módulos opcionales; docs/social-login.md, docs/otp-login.md)
        $this->app->bind(SocialLoginServiceInterface::class, SocialLoginService::class); // module:social-login
        $this->app->bind(SocialIdentityResolverInterface::class, ProviderIdentityResolver::class); // module:social-login
        $this->app->bind(OtpLoginServiceInterface::class, OtpLoginService::class); // module:otp-login

        $this->app->singleton(HostResolverInterface::class, DnsHostResolver::class);

        // Estado POR PETICIÓN/JOB (tenant activo): scoped, nunca singleton ni estático (docs/multi-tenancy.md).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
