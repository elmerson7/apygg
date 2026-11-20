<?php

/*
|--------------------------------------------------------------------------
| Tests de Arquitectura
|--------------------------------------------------------------------------
|
| Comprueban reglas estructurales del código, no comportamiento. Son rápidos
| (no tocan la BD) y atrapan lo que una revisión humana deja pasar cuando el
| proyecto crece: un dd() olvidado, lógica de negocio colándose en un
| controlador o un modelo que empieza a conocer la capa HTTP.
|
| Se ejecutan con el resto de la suite, o sueltos: make test-arch
|
*/

arch('no quedan restos de depuración')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('los controladores no acceden a env() directamente')
    ->expect('App\Http\Controllers')
    ->not->toUse('env');

arch('los servicios no acceden a env() directamente')
    ->expect('App\Services')
    ->not->toUse('env');

arch('los modelos no conocen la capa HTTP')
    ->expect('App\Models')
    ->not->toUse(['App\Http\Controllers', 'App\Http\Requests', 'App\Http\Resources']);

arch('los modelos no dependen de servicios')
    ->expect('App\Models')
    ->not->toUse('App\Services');

arch('los controladores no consultan la BD directamente')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB')
    // El health check tiene que probar la conexión: es su cometido.
    ->ignoring('App\Http\Controllers\Health\HealthController');

arch('los Form Requests heredan de la base del proyecto')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend('App\Http\Requests\BaseFormRequest')
    ->ignoring('App\Http\Requests\BaseFormRequest');

arch('los jobs se encolan')
    ->expect('App\Jobs')
    ->classes()
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->ignoring('App\Jobs\Job');

arch('los contratos son interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

arch('los enums son enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('las reglas de validación implementan el contrato de Laravel')
    ->expect('App\Rules')
    ->classes()
    ->toImplement('Illuminate\Contracts\Validation\ValidationRule')
    ->ignoring('App\Rules\StrongPassword');

/*
|--------------------------------------------------------------------------
| Inyección por interfaz (AGENTS.md → "Arquitectura")
|--------------------------------------------------------------------------
*/

/**
 * Todos los .php de una carpeta (rutas relativas a la raíz del proyecto).
 *
 * @return array<int, string>
 */
function archPhpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

test('los controladores no dependen de servicios concretos: solo de App\Contracts', function () {
    // Fachadas estáticas de uso global (sin interfaz a propósito): logging y caché.
    $fachadas = ['App\Services\LogService', 'App\Services\CacheService'];

    // Excepciones justificadas, por archivo: LlmGateway es un constructor fluido con
    // estado por llamada (useCase()->module()->chat()), no un servicio inyectable.
    $excepciones = [
        'app/Http/Controllers/Llm/LlmAreaController.php' => ['App\Services\LLM\LlmGateway'],
    ];

    $violaciones = [];

    foreach (archPhpFiles('app/Http/Controllers') as $file) {
        preg_match_all('/^use (App\\\\Services\\\\[^;]+);/m', file_get_contents(base_path($file)), $matches);

        foreach ($matches[1] as $import) {
            $permitido = in_array($import, $fachadas, true)
                || in_array($import, $excepciones[$file] ?? [], true);

            if (! $permitido) {
                $violaciones[] = "{$file} importa {$import}";
            }
        }
    }

    expect($violaciones)->toBe([]);
});

/**
 * Clase concreta => interfaz que debe implementar. Añade aquí cada servicio nuevo.
 *
 * @return array<string, string>
 */
function archServiciosConInterfaz(): array
{
    return [
        'App\Services\AuthService' => 'App\Contracts\AuthServiceInterface',
        'App\Services\UserService' => 'App\Contracts\UserServiceInterface',
        'App\Services\RoleService' => 'App\Contracts\RoleServiceInterface',
        'App\Services\PermissionService' => 'App\Contracts\PermissionServiceInterface',
        'App\Services\TokenService' => 'App\Contracts\TokenServiceInterface',
        'App\Services\ApiKeyService' => 'App\Contracts\ApiKeyServiceInterface',
        'App\Services\IdempotencyService' => 'App\Contracts\IdempotencyInterface',
        'App\Services\WebhookService' => 'App\Contracts\WebhookServiceInterface',
        'App\Services\FileService' => 'App\Contracts\FileServiceInterface',
        'App\Services\SettingsService' => 'App\Contracts\SettingsServiceInterface',
        'App\Services\SettingsRegistry' => 'App\Contracts\SettingsRegistryInterface',
        'App\Services\FeatureFlagService' => 'App\Contracts\FeatureFlagServiceInterface',
        'App\Services\PlanLimits' => 'App\Contracts\PlanLimitsInterface',
        'App\Services\MailService' => 'App\Contracts\MailServiceInterface',
        'App\Services\NotificationService' => 'App\Contracts\NotificationServiceInterface',
        'App\Services\SecurityService' => 'App\Contracts\SecurityServiceInterface',
        'App\Services\PasswordService' => 'App\Contracts\PasswordServiceInterface',
        'App\Services\BackupService' => 'App\Contracts\BackupServiceInterface',
        'App\Services\SecurityAuditService' => 'App\Contracts\SecurityAuditServiceInterface',
        'App\Services\Menu\MenuService' => 'App\Contracts\MenuServiceInterface',
        'App\Services\Docs\DocsService' => 'App\Contracts\DocsServiceInterface',
        'App\Services\Docs\DocsIndexer' => 'App\Contracts\DocsIndexerInterface',
        'App\Services\Mail\MailAccountService' => 'App\Contracts\MailAccountServiceInterface',
        'App\Services\Mail\MailEventStatusService' => 'App\Contracts\MailEventStatusServiceInterface',
        'App\Services\Mail\MailSendPolicy' => 'App\Contracts\MailSendPolicyInterface',
        'App\Services\Mail\MailSuppressionService' => 'App\Contracts\MailSuppressionServiceInterface',
        'App\Services\Mail\BrandResolver' => 'App\Contracts\BrandResolverInterface',
        'App\Services\Mail\SvixSignatureVerifier' => 'App\Contracts\WebhookSignatureVerifierInterface',
        'App\Services\Webhooks\InboundWebhookService' => 'App\Contracts\InboundWebhookServiceInterface',
        'App\Services\Webhooks\DnsHostResolver' => 'App\Contracts\HostResolverInterface',
        'App\Services\I18n\LocaleResolver' => 'App\Contracts\LocaleResolverInterface',
        'App\Services\I18n\LocaleSettingsService' => 'App\Contracts\LocaleSettingsServiceInterface',
        'App\Services\TenantOnboardingService' => 'App\Contracts\TenantOnboardingServiceInterface',
        'App\Services\TenantInvitationService' => 'App\Contracts\TenantInvitationServiceInterface',
        'App\Services\TenantMemberService' => 'App\Contracts\TenantMemberServiceInterface',
        'App\Services\Notifications\InboxService' => 'App\Contracts\InboxServiceInterface',
        'App\Services\Notifications\DeviceService' => 'App\Contracts\DeviceServiceInterface',
        'App\Services\Notifications\NotificationPreferenceService' => 'App\Contracts\NotificationPreferenceServiceInterface',
        'App\Services\Notifications\NotificationDeliveryService' => 'App\Contracts\NotificationDeliveryServiceInterface',
    ];
}

dataset('servicios con interfaz', function () {
    foreach (archServiciosConInterfaz() as $class => $interface) {
        yield class_basename($class) => [$class, $interface];
    }
});

test('cada servicio con interfaz la implementa y el contenedor la resuelve', function (string $class, string $interface) {
    // TestCase sustituye algunas interfaces por fakes (p. ej. el DNS): se resuelve el binding real.
    app()->forgetInstance($interface);

    expect(interface_exists($interface))->toBeTrue()
        ->and(class_implements($class))->toContain($interface)
        ->and(app($interface))->toBeInstanceOf($class);
})->with('servicios con interfaz');

test('toda clase de App\Services tiene interfaz o está en la lista de excepciones', function () {
    // Servicios sin interfaz, con el motivo:
    $sinInterfaz = [
        'App\Services\LogService' => 'fachada estática global (LogService::info...)',
        'App\Services\CacheService' => 'fachada estática global (CacheService::remember...)',
        'App\Services\Webhooks\WebhookUrlGuard' => 'colaborador interno de WebhookService y SafeWebhookUrl (su única dependencia, el DNS, sí tiene interfaz)',
    ];

    // Subcarpetas enteras sin interfaz: LLM (gateway fluido con estado por llamada, con sus propios contratos),
    // Logging (fachadas estáticas de registro) y Tools (utilidades del agente LLM).
    $carpetasLibres = ['app/Services/LLM/', 'app/Services/Logging/', 'app/Services/Tools/'];

    $conocidas = [...array_keys(archServiciosConInterfaz()), ...array_keys($sinInterfaz)];

    $desconocidas = [];

    foreach (archPhpFiles('app/Services') as $file) {
        if (collect($carpetasLibres)->contains(fn ($c) => str_starts_with($file, $c))) {
            continue;
        }

        $class = 'App\\'.str_replace(['app/', '/', '.php'], ['', '\\', ''], $file);

        if (! in_array($class, $conocidas, true)) {
            $desconocidas[] = $class;
        }
    }

    expect($desconocidas)->toBe([]);
});

test('no hay propiedades estáticas mutables en App (estado compartido en Octane)', function () {
    // PHP no admite `static readonly`: cualquier propiedad estática es mutable. Usa constantes,
    // config, atributos de la petición o un binding del contenedor.
    // Excepciones justificadas:
    // - app/Filament/: Filament exige metadatos estáticos ($model, $navigationIcon, $slug...)
    //   que se fijan en la declaración y nunca se mutan en tiempo de ejecución.
    $permitidas = [];
    $carpetasPermitidas = ['app/Filament/'];

    $violaciones = [];

    foreach (archPhpFiles('app') as $file) {
        if (in_array($file, $permitidas, true) || str_starts_with($file, $carpetasPermitidas[0])) {
            continue;
        }

        if (preg_match('/^\s*(?:(?:public|protected|private|readonly)\s+)*static\s+(?:\??[\w\\\\|]+\s+)?\$\w+/m', file_get_contents(base_path($file)))) {
            $violaciones[] = $file;
        }
    }

    expect($violaciones)->toBe([]);
});

test('no se usa auth() ni Auth::id()/user()/check() sin guard explícito en App', function () {
    // El guard por defecto (`auth.defaults.guard`, `AUTH_GUARD`) no tiene por qué ser el de la
    // API (`api`, JWT): `auth()->user()` puede devolver null aunque haya un token válido.
    // Usa `auth('api')`, `auth('web')` (back-office), `$request->user()` o `App\Helpers\Actor`
    // (api y luego web, para logs y auditoría).
    // Excepciones (archivo => motivo): ninguna por ahora.
    $excepciones = [];

    $violaciones = [];

    foreach (archPhpFiles('app') as $file) {
        if (isset($excepciones[$file])) {
            continue;
        }

        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', file_get_contents(base_path($file)));

        if (preg_match('/\bauth\(\s*\)\s*->|\bAuth::(?:id|user|check|guest|hasUser)\s*\(/', $code)) {
            $violaciones[] = $file;
        }
    }

    expect($violaciones)->toBe([]);
});

test('el aislamiento por tenant solo se salta con TenantQuery::withoutTenant (ni withoutGlobalScope de TenantScope ni withoutGlobalScopes sueltos)', function () {
    $violaciones = [];

    foreach (archPhpFiles('app') as $file) {
        if (str_starts_with($file, 'app/Support/Tenancy/') || $file === 'app/Scopes/TenantScope.php' || $file === 'app/Traits/BelongsToTenant.php') {
            continue;
        }

        // `withoutGlobalScope(TenantScope...)` y `withoutGlobalScopes(` (quita TODOS los scopes, el de tenant incluido).
        // Solo se admite quitar únicamente SoftDeletingScope: `withoutGlobalScopes([SoftDeletingScope::class])`.
        if (preg_match('/withoutGlobalScope\(\s*(?:new\s+)?TenantScope|withoutGlobalScopes\s*\((?!\s*\[\s*SoftDeletingScope::class\s*\]\s*\))/', file_get_contents(base_path($file)))) {
            $violaciones[] = $file;
        }
    }

    expect($violaciones)->toBe([]);
});

arch('Money y las secuencias no usan float ni dependen de la capa HTTP')
    ->expect(['App\Support\Money', 'App\Support\Sequences'])
    ->not->toUse(['App\Http\Controllers', 'App\Http\Requests', 'App\Http\Resources']);

test('las migraciones no guardan dinero en float, double ni decimal (docs/money.md)', function () {
    $offenders = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        $code = (string) file_get_contents($file);

        if (preg_match_all('/->(float|double|decimal|unsignedDecimal)\(\s*[\'"]([a-z0-9_]*(amount|price|total|balance)[a-z0-9_]*)[\'"]/i', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $offenders[] = basename($file).": {$hit[1]}('{$hit[2]}')";
            }
        }
    }

    expect($offenders)->toBe([], 'El dinero va en `<concepto>_minor` bigint + `currency` char(3) (docs/money.md). Columnas: '.implode(', ', $offenders));
});
