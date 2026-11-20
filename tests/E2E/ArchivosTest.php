<?php

use Illuminate\Support\Facades\Http;

/*
| Recorrido E2E "archivos" por HTTP real contra el S3 local del stack (RustFS):
|   subida directa presignada (intención -> PUT al bucket -> completar) con Idempotency-Key -> descarga con
|   autorización -> URL temporal firmada -> uso de cuota -> tipo declarado falso rechazado (se borra el objeto)
|   -> aislamiento entre empresas.
| Se omite si el stack no tiene S3 (E2E_S3_ENDPOINT) ni multiempresa.
*/

const E2E_AR = 'archivos';

beforeEach(fn () => e2eRequiereMultiempresa());

/** El host del PUT presignado viene del `AWS_ENDPOINT` del stack (http://s3:9000): alcanzable desde el runner. */
test('una empresa pide la subida directa, sube al bucket y completa el archivo', function () {
    if (! getenv('E2E_S3_ENDPOINT')) {
        $this->markTestSkipped('E2E_S3_ENDPOINT no definida: sin S3 local no hay subida presignada.');
    }
    $u = e2eUsuario(E2E_AR, 'dueno-a');
    $empresa = e2eEmpresa($u['access'], E2E_AR, 'a');
    $s = e2e(E2E_AR);
    $s['a'] = $u + ['empresa' => $empresa];

    $png = e2ePngMinimo();
    $clave = 'up-'.$s['run'];
    $cuerpo = ['name' => 'e2e-'.$s['run'].'.png', 'size' => strlen($png), 'mime_type' => 'image/png', 'checksum_sha256' => hash('sha256', $png), 'is_public' => false];

    $intencion = e2eApi($u['access'], $empresa['slug'])->withHeaders(['Idempotency-Key' => $clave])->post('/files/uploads', $cuerpo);
    expect($intencion->status())->toBe(201, $intencion->body())
        ->and($intencion->json('data.upload.method'))->toBe('PUT');

    // El reintento devuelve la MISMA intención (una sola reserva de cuota).
    $repetida = e2eApi($u['access'], $empresa['slug'])->withHeaders(['Idempotency-Key' => $clave])->post('/files/uploads', $cuerpo);
    expect($repetida->header('Idempotent-Replayed'))->toBe('true')->and($repetida->json('data.id'))->toBe($intencion->json('data.id'));

    // El cliente sube DIRECTO al bucket con la URL firmada (la API no toca los bytes).
    $put = Http::withHeaders((array) $intencion->json('data.upload.headers'))->withBody($png, 'image/png')->put($intencion->json('data.upload.url'));
    expect($put->successful())->toBeTrue('PUT al bucket: '.$put->status().' '.$put->body());

    $completa = e2eApi($u['access'], $empresa['slug'])->post('/files/uploads/'.$intencion->json('data.id').'/complete');
    expect($completa->status())->toBe(200, $completa->body())
        ->and($completa->json('data.size'))->toBe(strlen($png));

    $s['archivo'] = $completa->json('data.id');
    $s['png'] = $png;
});

test('el archivo se descarga con autorizacion, con URL temporal y cuenta en la cuota', function () {
    $s = e2e(E2E_AR);
    $a = $s['a'];

    $bajada = e2eApi($a['access'], $a['empresa']['slug'])->get('/files/'.$s['archivo'].'/download');
    expect($bajada->status())->toBe(200)->and($bajada->body())->toBe($s['png']);

    $temporal = e2eApi($a['access'], $a['empresa']['slug'])->get('/files/'.$s['archivo'].'/temporary-url');
    expect($temporal->status())->toBe(200);
    $url = (string) ($temporal->json('data.url') ?? $temporal->json('data.temporary_url'));
    expect($url)->not->toBeEmpty();
    // La URL firmada se usa SIN token de sesión.
    $sinToken = str_starts_with($url, 'http') ? Http::get($url) : e2eApi()->get($url);
    expect($sinToken->status())->toBe(200)->and($sinToken->body())->toBe($s['png']);

    $uso = e2eApi($a['access'], $a['empresa']['slug'])->get('/files/usage');
    expect($uso->status())->toBe(200)->and($uso->json('data.used_bytes'))->toBeGreaterThanOrEqual(strlen($s['png']));
})->depends('una empresa pide la subida directa, sube al bucket y completa el archivo');

test('un archivo con tipo declarado falso se rechaza al completar y no queda en la empresa', function () {
    $s = e2e(E2E_AR);
    $a = $s['a'];
    $html = '<html><body>no soy un png</body></html>';

    $intencion = e2eApi($a['access'], $a['empresa']['slug'])->post('/files/uploads', ['name' => 'falso-'.$s['run'].'.png', 'size' => strlen($html), 'mime_type' => 'image/png']);
    expect($intencion->status())->toBe(201, $intencion->body());

    $put = Http::withHeaders((array) $intencion->json('data.upload.headers'))->withBody($html, 'image/png')->put($intencion->json('data.upload.url'));
    expect($put->successful())->toBeTrue();

    $completa = e2eApi($a['access'], $a['empresa']['slug'])->post('/files/uploads/'.$intencion->json('data.id').'/complete');
    expect($completa->status())->toBe(422)->and($completa->json('code'))->toBe('upload_mime_mismatch');

    $nombres = collect(e2eItems(e2eApi($a['access'], $a['empresa']['slug'])->get('/files')))->pluck('filename')->all();
    expect(implode(',', $nombres))->not->toContain('falso-'.$s['run']);
})->depends('el archivo se descarga con autorizacion, con URL temporal y cuenta en la cuota');

test('aislamiento A/B: otra empresa no ve, ni descarga, ni borra el archivo', function () {
    $s = e2e(E2E_AR);
    $b = e2eUsuario(E2E_AR, 'dueno-b');
    $empresaB = e2eEmpresa($b['access'], E2E_AR, 'b');
    $id = $s['archivo'];

    expect(e2eApi($b['access'], $empresaB['slug'])->get("/files/{$id}")->status())->toBe(404);
    expect(e2eApi($b['access'], $empresaB['slug'])->get("/files/{$id}/download")->status())->toBe(404);
    expect(e2eApi($b['access'], $empresaB['slug'])->delete("/files/{$id}")->status())->toBe(404);
    expect(e2eItems(e2eApi($b['access'], $empresaB['slug'])->get('/files')))->toBeEmpty();
    // Declarando la empresa de A sin ser miembro: 403.
    expect(e2eApi($b['access'], $s['a']['empresa']['slug'])->get("/files/{$id}")->status())->toBe(403);

    // Y el archivo de A sigue intacto.
    expect(e2eApi($s['a']['access'], $s['a']['empresa']['slug'])->get("/files/{$id}/download")->status())->toBe(200);
    // Borrarlo libera la cuota.
    expect(e2eApi($s['a']['access'], $s['a']['empresa']['slug'])->delete("/files/{$id}")->status())->toBe(200);
    expect(e2eApi($s['a']['access'], $s['a']['empresa']['slug'])->get("/files/{$id}")->status())->toBe(404);
})->depends('un archivo con tipo declarado falso se rechaza al completar y no queda en la empresa');
