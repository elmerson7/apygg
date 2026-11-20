<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Se omite entera si E2E_BASE_URL no está definida (Tests\Support\E2ETestCase), así `make test` no la necesita.
| Recorrido E2E: habla por HTTP REAL con un stack levantado (Octane, Horizon, Mailpit y,
| si se indica, S3). No usa el kernel de Laravel ($this->get()): solo el cliente HTTP.
|
|   E2E_BASE_URL               API bajo prueba (obligatoria)
|   E2E_MAIL_URL               API de Mailpit (si falta, se omite el paso del correo)
|   E2E_S3_ENDPOINT            endpoint S3 (opcional; comprueba que el privado está en el bucket)
|   E2E_WEBHOOK_RECEIVER_URL   URL del receptor de webhooks VISTA DESDE el stack (por defecto http://app:9199)
| Los tests van en orden y dependen unos de otros: si uno falla, los siguientes se omiten.
| Los helpers e2e*() viven en tests/E2E/helpers.php (los comparten todos los recorridos).
| Cómo levantar el stack y correrlo: docs/testing.md ("Pruebas E2E").
*/

// ── 1. Salud y ficha ─────────────────────────────────────────────────────────

test('la API responde lista y la raiz devuelve la ficha con el titulo', function () {
    $ready = e2eApi()->get('/health/ready');
    expect($ready->status())->toBe(200)
        ->and($ready->json('status'))->toBe('ready')
        ->and($ready->json('services.database.status'))->toBe('ok')
        ->and($ready->json('services.redis.status'))->toBe('ok');

    $ficha = e2eApi()->get('/');
    expect($ficha->status())->toBe(200)
        ->and($ficha->json('status'))->toBe('running')
        ->and($ficha->json('name'))->toBeString()->not->toBeEmpty()
        ->and($ficha->json('endpoints.health_ready'))->toBe('/health/ready');

    if ($titulo = getenv('E2E_APP_TITLE')) {
        expect($ficha->json('name'))->toBe($titulo);
    }
});

// ── 2. Registro ──────────────────────────────────────────────────────────────

test('un usuario nuevo se registra y recibe tokens', function () {
    $email = 'e2e-'.e2e()['run'].'@example.test';
    e2e()['email'] = $email;

    $res = e2eRegistrar('Usuario E2E', $email);

    expect($res->status())->toBe(201)
        ->and($res->json('success'))->toBeTrue()
        ->and($res->json('data.user.email'))->toBe($email)
        ->and($res->json('data.access_token'))->toBeString()->not->toBeEmpty()
        ->and($res->json('data.refresh_token'))->toBeString()->not->toBeEmpty();
})->depends('la API responde lista y la raiz devuelve la ficha con el titulo');

test('un registro con el mismo email se rechaza', function () {
    $res = e2eRegistrar('Duplicado', e2e()['email']);

    expect($res->status())->toBe(422);
})->depends('un usuario nuevo se registra y recibe tokens');

// ── 3. Correo de bienvenida por la cola real ─────────────────────────────────

test('el correo de bienvenida llega a Mailpit por la cola', function () {
    $mailpit = getenv('E2E_MAIL_URL');
    if (! $mailpit) {
        $this->markTestSkipped('E2E_MAIL_URL no definida: sin Mailpit.');
    }

    $email = e2e()['email'];
    // Al registrarse salen DOS correos por la cola (bienvenida y verificación): se busca el de bienvenida.
    $mensaje = e2eEsperar(function () use ($mailpit, $email) {
        $mensajes = Http::timeout(10)->get(rtrim($mailpit, '/').'/api/v1/search', ['query' => 'to:'.$email])->json('messages') ?? [];

        return collect($mensajes)->first(fn ($m) => str_starts_with((string) $m['Subject'], 'Bienvenido'));
    });

    expect($mensaje)->not->toBeNull('el correo de bienvenida no llegó en 30 s: ¿Horizon está consumiendo la cola default?')
        ->and($mensaje['To'][0]['Address'])->toBe($email);
})->depends('un usuario nuevo se registra y recibe tokens');

// ── 4. Login, sesión y rotación de tokens ────────────────────────────────────

test('el login devuelve access y refresh y /auth/me identifica al usuario', function () {
    $res = e2ePostAuth('/auth/login', ['login' => e2e()['email'], 'password' => e2e()['password']]);

    expect($res->status())->toBe(200)
        ->and($res->json('data.access_token'))->toBeString()
        ->and($res->json('data.refresh_token'))->toBeString();

    e2e()['access'] = $res->json('data.access_token');
    e2e()['refresh'] = $res->json('data.refresh_token');

    $me = e2eApi(e2e()['access'])->get('/auth/me');
    expect($me->status())->toBe(200)
        ->and($me->json('data.email'))->toBe(e2e()['email']);

    // Con multiempresa activada (MULTI_TENANCY_ENABLED) los archivos y webhooks cuelgan de una empresa:
    // se da de alta una y se declara con X-Tenant. Con multiempresa apagada responde 404 y se sigue sin tenant.
    $empresa = e2eApi(e2e()['access'])->post('/tenants', ['name' => 'Base '.e2e()['run'], 'slug' => 'e2e-base-'.e2e()['run']]);
    if ($empresa->status() === 201) {
        e2e()['tenant'] = 'e2e-base-'.e2e()['run'];
    } else {
        expect($empresa->status())->toBeIn([403, 404], 'alta de empresa: '.$empresa->body());
    }
})->depends('un usuario nuevo se registra y recibe tokens');

test('el login con clave incorrecta se rechaza', function () {
    $res = e2ePostAuth('/auth/login', ['login' => e2e()['email'], 'password' => 'Incorrecta-123!x']);

    expect($res->status())->toBe(401);
})->depends('el login devuelve access y refresh y /auth/me identifica al usuario');

test('el refresh rota los tokens y el refresh viejo ya no sirve', function () {
    $viejo = e2e()['refresh'];

    $res = e2eApi()->post('/auth/refresh', ['refresh_token' => $viejo]);
    expect($res->status())->toBe(200)
        ->and($res->json('data.refresh_token'))->not->toBe($viejo);

    e2e()['access'] = $res->json('data.access_token');
    e2e()['refresh'] = $res->json('data.refresh_token');

    // El refresh usado queda revocado.
    expect(e2eApi()->post('/auth/refresh', ['refresh_token' => $viejo])->status())->toBe(401);

    // El access nuevo funciona.
    expect(e2eApi(e2e()['access'])->get('/auth/me')->status())->toBe(200);
})->depends('el login devuelve access y refresh y /auth/me identifica al usuario');

test('el refresh token no sirve como access token', function () {
    expect(e2eApi(e2e()['refresh'])->get('/auth/me')->status())->toBe(401);
})->depends('el refresh rota los tokens y el refresh viejo ya no sirve');

// ── 5. Archivo privado ───────────────────────────────────────────────────────

test('un archivo privado se sube y se descarga con autorizacion', function () {
    $png = e2ePngMinimo();

    $res = e2eApi(e2e()['access'], e2e()['tenant'] ?? null)
        ->attach('file', $png, 'e2e.png', ['Content-Type' => 'image/png'])
        ->post('/files', ['is_public' => '0']);

    expect($res->status())->toBe(201)
        ->and($res->json('data.is_public'))->toBeFalse()
        ->and($res->json('data.path'))->toBeNull('un archivo privado no expone su ruta');

    e2e()['archivo'] = $res->json('data.id');
    e2e()['archivo_nombre'] = $res->json('data.filename');

    $bajada = e2eApi(e2e()['access'], e2e()['tenant'] ?? null)->get('/files/'.e2e()['archivo'].'/download');
    expect($bajada->status())->toBe(200)
        ->and($bajada->body())->toBe($png);

    // Con S3 definido, el objeto privado vive de verdad en el bucket privado.
    if ($endpoint = getenv('E2E_S3_ENDPOINT')) {
        $disco = Storage::build([
            'driver' => 's3',
            'key' => getenv('E2E_S3_KEY') ?: 'apygg-dev',
            'secret' => getenv('E2E_S3_SECRET') ?: 'apygg-dev-secret',
            'region' => 'us-east-1',
            'endpoint' => rtrim($endpoint, '/'),
            'use_path_style_endpoint' => true,
            'bucket' => getenv('E2E_S3_PRIVATE_BUCKET') ?: 'apygg-private',
            'throw' => false,
        ]);
        $rutas = collect($disco->allFiles())->filter(fn ($r) => str_ends_with($r, e2e()['archivo_nombre']));
        expect($rutas)->toHaveCount(1, 'el archivo privado no está en el bucket privado: ¿FILE_DISK_PRIVATE=s3_private?');
    }
})->depends('el refresh rota los tokens y el refresh viejo ya no sirve');

test('la descarga sin token es 401 y con otro usuario es 403 o 404', function () {
    $ruta = '/files/'.e2e()['archivo'].'/download';

    expect(e2eApi()->get($ruta)->status())->toBe(401);

    $otro = e2eRegistrar('Otro E2E', 'e2e-otro-'.e2e()['run'].'@example.test');
    expect($otro->status())->toBe(201);

    expect(e2eApi($otro->json('data.access_token'), e2e()['tenant'] ?? null)->get($ruta)->status())->toBeIn([403, 404]);
})->depends('un archivo privado se sube y se descarga con autorizacion');

// ── 6. Webhook: entrega firmada a un receptor real ───────────────────────────

test('un webhook entrega user.logged_in con firma valida', function () {
    $url = getenv('E2E_WEBHOOK_RECEIVER_URL') ?: 'http://app:9199';
    $puerto = (int) (parse_url($url, PHP_URL_PORT) ?: 9199);

    // Receptor mínimo dentro del propio test: escucha en todas las interfaces y el stack
    // (Horizon) le entrega desde su red. Requiere WEBHOOKS_ALLOW_HTTP y WEBHOOKS_ALLOWED_HOSTS.
    $servidor = @stream_socket_server("tcp://0.0.0.0:{$puerto}", $errno, $error);
    expect($servidor)->not->toBeFalse("no se pudo abrir el receptor en :{$puerto}: {$error}");

    $creado = e2eApi(e2e()['access'], e2e()['tenant'] ?? null)->post('/webhooks', [
        'name' => 'e2e '.e2e()['run'],
        'url' => $url,
        'events' => ['user.logged_in'],
        'timeout' => 10,
        'max_retries' => 1,
    ]);
    expect($creado->status())->toBe(201, 'el stack debe tener WEBHOOKS_ALLOW_HTTP=true y WEBHOOKS_ALLOWED_HOSTS con el host del receptor: '.$creado->body());

    $secreto = $creado->json('data.secret');
    e2e()['webhook'] = $creado->json('data.id');
    expect($secreto)->toBeString()->not->toBeEmpty();

    // Un login del dueño dispara user.logged_in; la entrega va por la cola `webhooks`.
    expect(e2ePostAuth('/auth/login', ['login' => e2e()['email'], 'password' => e2e()['password']])->status())->toBe(200);

    // Con multiempresa un webhook solo recibe eventos ocurridos DENTRO de su tenant (docs/webhooks.md) y el login
    // ocurre fuera de cualquiera: la prueba pasa a ser que NO se entrega nada (falla cerrado). Sin multiempresa,
    // la entrega firmada llega.
    $multiempresa = isset(e2e()['tenant']);

    $peticion = null;
    $limite = microtime(true) + ($multiempresa ? 8 : 45);
    while ($peticion === null && microtime(true) < $limite) {
        $lectura = [$servidor];
        $escritura = $excepcion = null;
        if (stream_select($lectura, $escritura, $excepcion, 1) < 1) {
            continue;
        }
        $conexion = @stream_socket_accept($servidor, 1);
        if (! $conexion) {
            continue;
        }
        stream_set_timeout($conexion, 5);

        $cabeceras = '';
        while (! str_contains($cabeceras, "\r\n\r\n") && ! feof($conexion)) {
            $trozo = fread($conexion, 8192);
            if ($trozo === false || $trozo === '') {
                break;
            }
            $cabeceras .= $trozo;
        }
        [$cabecera, $cuerpo] = array_pad(explode("\r\n\r\n", $cabeceras, 2), 2, '');
        $largo = preg_match('/^content-length:\s*(\d+)/mi', $cabecera, $m) ? (int) $m[1] : 0;
        while (strlen($cuerpo) < $largo && ! feof($conexion)) {
            $trozo = fread($conexion, 8192);
            if ($trozo === false || $trozo === '') {
                break;
            }
            $cuerpo .= $trozo;
        }
        fwrite($conexion, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
        fclose($conexion);

        $valores = [];
        foreach (explode("\r\n", $cabecera) as $linea) {
            if (str_contains($linea, ':')) {
                [$k, $v] = explode(':', $linea, 2);
                $valores[strtolower(trim($k))] = trim($v);
            }
        }
        $peticion = ['headers' => $valores, 'body' => $cuerpo];
    }
    fclose($servidor);

    if ($multiempresa) {
        expect($peticion)->toBeNull('un evento sin tenant no debe entregarse a un webhook de un tenant');

        return;
    }

    expect($peticion)->not->toBeNull('no llegó ninguna entrega en 45 s: ¿Horizon consume la cola webhooks?');

    $marca = (int) ($peticion['headers']['x-webhook-timestamp'] ?? 0);
    $firma = $peticion['headers']['x-webhook-signature'] ?? '';
    $esperada = 'sha256='.hash_hmac('sha256', $marca.'.'.$peticion['body'], $secreto);

    expect($marca)->toBeGreaterThan(time() - 300)
        ->and($firma)->toBe($esperada)
        ->and(json_decode($peticion['body'], true)['event']['type'] ?? null)->toBe('user.logged_in')
        ->and(json_decode($peticion['body'], true)['data']['user']['email'] ?? null)->toBe(e2e()['email']);

    // Una firma con otro secreto no coincide: la verificación distingue de verdad.
    expect($firma)->not->toBe('sha256='.hash_hmac('sha256', $marca.'.'.$peticion['body'], 'otro-secreto'));
})->depends('un archivo privado se sube y se descarga con autorizacion');

// ── 7. Logout ────────────────────────────────────────────────────────────────

test('el logout invalida el access token', function () {
    $access = e2e()['access'];

    // Limpieza: el webhook ya no hace falta (el logout también dispara un evento).
    if (isset(e2e()['webhook'])) {
        expect(e2eApi($access, e2e()['tenant'] ?? null)->delete('/webhooks/'.e2e()['webhook'])->status())->toBe(200);
    }
    if (isset(e2e()['archivo'])) {
        expect(e2eApi($access, e2e()['tenant'] ?? null)->delete('/files/'.e2e()['archivo'])->status())->toBe(200);
    }

    expect(e2eApi($access)->get('/auth/me')->status())->toBe(200);

    $salida = e2eApi($access)->post('/auth/logout', ['refresh_token' => e2e()['refresh']]);
    expect($salida->status())->toBe(200);

    expect(e2eApi($access)->get('/auth/me')->status())->toBe(401)
        ->and(e2eApi()->post('/auth/refresh', ['refresh_token' => e2e()['refresh']])->status())->toBe(401);
})->depends('un archivo privado se sube y se descarga con autorizacion');
