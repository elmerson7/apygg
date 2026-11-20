<?php

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
| Helpers compartidos por todos los recorridos de tests/E2E (los carga tests/Pest.php).
| Todo habla con el stack por HTTP real; solo dos cosas tocan el stack por dentro, y ambas son de
| PRUEBAS: e2eViajar() adelanta fechas en la BD del stack y e2eArtisan() lanza un comando del cron
| (billing:renew...) contra él. Sin E2E_ARTISAN=1 (stack remoto) los recorridos que los necesitan se omiten.
*/

/**
 * Estado de un recorrido (una instancia por nombre y proceso). Los pasos van en orden y se pasan
 * datos por aquí; `run` y `password` son comunes para que los datos sean únicos en cada ejecución.
 */
function e2e(string $recorrido = 'base'): ArrayObject
{
    static $estados = [];
    static $comun = null;

    $comun ??= [
        'run' => bin2hex(random_bytes(4)),
        'password' => 'E2e-Clave-'.bin2hex(random_bytes(6)).'!9',
    ];

    return $estados[$recorrido] ??= new ArrayObject($comun);
}

/** Cliente HTTP contra el stack, opcionalmente con token y tenant (`X-Tenant`: slug o id). */
function e2eApi(?string $token = null, string|int|null $tenant = null): PendingRequest
{
    $peticion = Http::baseUrl(rtrim((string) getenv('E2E_BASE_URL'), '/'))
        ->acceptJson()
        ->timeout(30);

    if ($tenant !== null) {
        $peticion = $peticion->withHeaders(['X-Tenant' => (string) $tenant]);
    }

    return $token ? $peticion->withToken($token) : $peticion;
}

/**
 * Los endpoints de autenticación limitan por minuto y por IP (5 por defecto; el stack E2E sube el tope):
 * ante un 429 espera el reinicio de la ventana y reintenta (acotado), para que la suite no sea frágil.
 *
 * @param  array<string, mixed>  $datos
 */
function e2ePostAuth(string $ruta, array $datos): Response
{
    for ($intento = 0; $intento < 3; $intento++) {
        $res = e2eApi()->post($ruta, $datos);
        if ($res->status() !== 429) {
            return $res;
        }
        sleep(max(1, min(65, (int) $res->header('Retry-After') ?: 61)));
    }

    return $res;
}

/** Espera (acotada) a que `$buscar` devuelva algo distinto de null. */
function e2eEsperar(Closure $buscar, int $segundos = 30): mixed
{
    $limite = microtime(true) + $segundos;
    do {
        $valor = $buscar();
        if ($valor !== null) {
            return $valor;
        }
        usleep(500_000);
    } while (microtime(true) < $limite);

    return null;
}

function e2eRegistrar(string $nombre, string $email, ?string $recorrido = null): Response
{
    $clave = e2e($recorrido ?? 'base')['password'];

    return e2ePostAuth('/auth/register', [
        'name' => $nombre,
        'email' => $email,
        'password' => $clave,
        'password_confirmation' => $clave,
    ]);
}

/**
 * Registra un usuario con email único por ejecución y devuelve sus datos y tokens.
 *
 * @return array{id: int, email: string, access: string, refresh: string, password: string}
 */
function e2eUsuario(string $recorrido, string $alias): array
{
    $email = 'e2e-'.$recorrido.'-'.$alias.'-'.e2e($recorrido)['run'].'@example.test';
    $res = e2eRegistrar('E2E '.$alias, $email, $recorrido);
    expect($res->status())->toBe(201, 'registro de '.$alias.': '.$res->body());

    return [
        'id' => (int) $res->json('data.user.id'),
        'email' => $email,
        'access' => (string) $res->json('data.access_token'),
        'refresh' => (string) $res->json('data.refresh_token'),
        'password' => (string) e2e($recorrido)['password'],
    ];
}

/**
 * Da de alta una empresa (`POST /tenants`) y devuelve su id y slug (único por ejecución).
 *
 * @return array{id: int, slug: string}
 */
function e2eEmpresa(string $token, string $recorrido, string $alias): array
{
    $slug = 'e2e-'.$recorrido.'-'.$alias.'-'.e2e($recorrido)['run'];
    $res = e2eApi($token)->post('/tenants', ['name' => 'Empresa '.$alias.' '.e2e($recorrido)['run'], 'slug' => $slug]);
    expect($res->status())->toBe(201, 'alta de empresa '.$alias.': '.$res->body());

    return ['id' => (int) $res->json('data.id'), 'slug' => $slug];
}

/** @return list<array<string, mixed>> */
function e2eItems(Response $res): array
{
    $datos = $res->json('data');

    return is_array($datos) && array_is_list($datos) ? $datos : (array) ($res->json('data.data') ?? $res->json('data.items') ?? []);
}

function e2ePngMinimo(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

// ── Correo (Mailpit) ─────────────────────────────────────────────────────────

/**
 * Espera el primer correo para `$email` (opcionalmente con `$asunto` en el asunto) y devuelve su texto plano.
 * Devuelve null si no llega a tiempo. Omite el test si el stack no tiene Mailpit.
 */
function e2eCorreo(string $email, ?string $asunto = null, int $segundos = 40): ?string
{
    $mailpit = getenv('E2E_MAIL_URL');
    if (! $mailpit) {
        test()->markTestSkipped('E2E_MAIL_URL no definida: sin Mailpit.');
    }
    $base = rtrim($mailpit, '/');

    $id = e2eEsperar(function () use ($base, $email, $asunto) {
        $mensajes = Http::timeout(10)->get($base.'/api/v1/search', ['query' => 'to:'.$email])->json('messages') ?? [];
        foreach ($mensajes as $mensaje) {
            if ($asunto === null || stripos((string) $mensaje['Subject'], $asunto) !== false) {
                return $mensaje['ID'];
            }
        }

        return null;
    }, $segundos);

    return $id === null ? null : (string) Http::timeout(10)->get($base.'/api/v1/message/'.$id)->json('Text');
}

/** Los recorridos de empresa, facturación y archivos por tenant necesitan multiempresa con alta libre en el stack. */
function e2eRequiereMultiempresa(): void
{
    if (getenv('E2E_MULTITENANT') !== '1') {
        test()->markTestSkipped('E2E_MULTITENANT=1 no definida: el stack necesita MULTI_TENANCY_ENABLED=true y TENANT_SIGNUP_ENABLED=true (make e2e lo levanta así).');
    }
}

// ── Pasos "por dentro" del stack (solo pruebas) ──────────────────────────────

/** Los pasos que adelantan el reloj o lanzan comandos solo corren dentro del runner del stack temporal. */
function e2eRequiereStackLocal(): void
{
    if (getenv('E2E_ARTISAN') !== '1') {
        test()->markTestSkipped('E2E_ARTISAN=1 no definida: este recorrido necesita acceso a la BD y a artisan del stack (make e2e).');
    }
}

/** Conexión PDO a la BD del stack (no la de tests: la que usa la API bajo prueba). */
function e2eDb(): PDO
{
    static $pdo = null;

    return $pdo ??= new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: 'postgres', getenv('DB_PORT') ?: '5432', getenv('E2E_DB_DATABASE') ?: getenv('DB_DATABASE')),
        (string) getenv('DB_USERNAME'),
        (string) getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * Adelanta el reloj de UNA empresa `$dias` días: retrocede esa cantidad todas las fechas de su suscripción,
 * facturas y cobros, de modo que "ahora" queda `$dias` días después de lo que ahí consta. Es lo que
 * permite recorrer prueba -> renovación -> impago -> bloqueo sin esperar.
 */
function e2eViajar(int $tenantId, int $dias): void
{
    e2eRequiereStackLocal();
    $intervalo = "interval '{$dias} days'";
    $db = e2eDb();

    $db->prepare("UPDATE subscriptions SET
        trial_ends_at = trial_ends_at - {$intervalo},
        billing_anchor_at = billing_anchor_at - {$intervalo},
        current_period_start = current_period_start - {$intervalo},
        current_period_end = current_period_end - {$intervalo},
        next_retry_at = next_retry_at - {$intervalo},
        created_at = created_at - {$intervalo}
        WHERE tenant_id = ?")->execute([$tenantId]);

    $db->prepare("UPDATE invoices SET
        period_start = period_start - {$intervalo},
        period_end = period_end - {$intervalo},
        issued_at = issued_at - {$intervalo},
        due_at = due_at - {$intervalo},
        paid_at = paid_at - {$intervalo},
        created_at = created_at - {$intervalo}
        WHERE tenant_id = ?")->execute([$tenantId]);
}

/**
 * Ejecuta un comando artisan (billing:renew, billing:notify...) contra el stack, desde el runner.
 * El proceso hijo hereda el entorno del runner salvo lo que phpunit.xml fuerza para los tests
 * (cola sync, caché en array, correo array...): se devuelve a lo que usa el stack real.
 */
function e2eArtisan(string $comando): string
{
    e2eRequiereStackLocal();

    $proceso = Process::fromShellCommandline('php artisan '.$comando.' --no-interaction', base_path(), [
        'APP_ENV' => 'dev',
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'MAIL_MAILER' => 'smtp',
        'SESSION_DRIVER' => 'array',
        'SCOUT_DRIVER' => 'null',
        'DB_DATABASE' => (string) (getenv('E2E_DB_DATABASE') ?: getenv('DB_DATABASE')),
    ]);
    $proceso->setTimeout(120)->run();

    expect($proceso->getExitCode())->toBe(0, "artisan {$comando} falló: ".$proceso->getErrorOutput().$proceso->getOutput());

    return $proceso->getOutput();
}
