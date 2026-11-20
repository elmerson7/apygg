<?php

namespace App\Providers\Filament;

use App\Filament\Http\Middleware\BackofficeSecurityHeaders;
use App\Filament\Policies\AuditLogPolicy;
use App\Filament\Policies\BlacklistPolicy;
use App\Filament\Policies\DataExportPolicy;
use App\Filament\Policies\DataImportPolicy;
use App\Filament\Policies\FeatureFlagPolicy;
use App\Filament\Policies\LegalDocumentPolicy;
use App\Filament\Policies\MailLogPolicy;
use App\Filament\Policies\MailManagePolicy;
use App\Filament\Policies\MailSuppressionPolicy;
use App\Filament\Policies\PersonalDataExportPolicy;
use App\Filament\Policies\SystemLogPolicy;
use App\Filament\Policies\TenantPolicy;
use App\Filament\Widgets\HealthStatusWidget;
use App\Http\Middleware\ResolveClientIpMiddleware;
use App\Models\AuditLog;
use App\Models\Blacklist;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\FeatureFlag;
use App\Models\LegalDocument;
use App\Models\Logs\ActivityLog;
use App\Models\Logs\ApiLog;
use App\Models\Logs\SecurityLog;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailSuppression;
use App\Models\Mail\MailTemplateConfig;
use App\Models\PersonalDataExport;
use App\Models\Tenant;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel "backoffice" (Filament): back-office interno del dueño de la plataforma.
 *
 * Módulo opcional: con BACKOFFICE_ENABLED=false el panel no se registra (sin rutas).
 * Ver config/backoffice.php y docs/backoffice.md.
 */
class BackofficePanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! config('backoffice.enabled')) {
            return;
        }

        parent::register();
    }

    public function boot(): void
    {
        if (! config('backoffice.enabled')) {
            return;
        }

        // Modelos que la API protege solo con middleware de permiso: aquí el mismo permiso
        // pasa a ser la policy que Filament exige (sin policy, Filament deniega).
        Gate::policy(ActivityLog::class, SystemLogPolicy::class);
        Gate::policy(ApiLog::class, SystemLogPolicy::class);
        Gate::policy(SecurityLog::class, SystemLogPolicy::class);
        Gate::policy(MailAccount::class, MailManagePolicy::class);
        Gate::policy(MailTemplateConfig::class, MailManagePolicy::class);
        Gate::policy(MailLog::class, MailLogPolicy::class);
        Gate::policy(Blacklist::class, BlacklistPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class);
        Gate::policy(MailSuppression::class, MailSuppressionPolicy::class);
        Gate::policy(LegalDocument::class, LegalDocumentPolicy::class);
        Gate::policy(DataExport::class, DataExportPolicy::class);
        Gate::policy(DataImport::class, DataImportPolicy::class);
        Gate::policy(PersonalDataExport::class, PersonalDataExportPolicy::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('backoffice')
            ->path(config('backoffice.path'))
            ->brandName(config('backoffice.brand'))
            ->colors(['primary' => Color::Indigo])
            // Sesión web con el guard `web` puesto a mano: bajo Octane el guard por defecto
            // puede ser `api` (JWT) si una petición anterior lo cambió.
            ->authGuard('web')
            ->login()
            // MFA obligatorio (TOTP por app + códigos de recuperación). Sin él configurado,
            // Filament redirige a la pantalla de configuración antes de dejar entrar.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->widgets([HealthStatusWidget::class])
            ->middleware([
                ResolveClientIpMiddleware::class, // IP real tras Cloudflare: el límite de intentos de login es por IP
                BackofficeSecurityHeaders::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
