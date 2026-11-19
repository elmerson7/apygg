<?php

use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| Integración con Mailpit REAL. Se omite si INTEGRATION_MAIL_URL no está definida
| (make test-integration la define). Ver docs/local-services.md.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $url = getenv('INTEGRATION_MAIL_URL');
    if (! $url) {
        $this->markTestSkipped('INTEGRATION_MAIL_URL no definida: sin Mailpit.');
    }

    $this->mailpit = rtrim($url, '/');
    $host = getenv('INTEGRATION_MAIL_SMTP_HOST') ?: parse_url($this->mailpit, PHP_URL_HOST);
    $port = (int) (getenv('INTEGRATION_MAIL_SMTP_PORT') ?: 1025);

    // Sin cuentas en la BD el correo sale por el mailer de respaldo (MAIL_MAILER):
    // aquí, el SMTP de Mailpit. El camino sigue siendo el único: accounts -> respaldo.
    config([
        'mail.fallback' => 'smtp',
        'mail.mailers.smtp.host' => $host,
        'mail.mailers.smtp.port' => $port,
        'mail.mailers.smtp.encryption' => null,
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
    ]);
    Mail::purge();
    Mail::purge('smtp');
    Mail::purge('accounts');
});

test('un correo enviado por el camino unico llega a la bandeja de Mailpit', function () {
    $asunto = 'Integracion '.bin2hex(random_bytes(6));
    $destino = 'integracion-'.bin2hex(random_bytes(3)).'@example.test';

    $enviado = app(MailService::class)->send(
        $destino,
        $asunto,
        'emails.test',
        ['app_name' => 'APYGG', 'account_name' => 'integracion', 'driver' => 'smtp', 'sent_at' => now()->toDateTimeString()],
        queue: false,
    );

    expect($enviado)->toBeTrue();

    $mensaje = null;
    for ($i = 0; $i < 20 && $mensaje === null; $i++) {
        $res = Http::get($this->mailpit.'/api/v1/search', ['query' => 'subject:"'.$asunto.'"']);
        $mensaje = $res->json('messages.0');
        $mensaje ?? usleep(250_000);
    }

    expect($mensaje)->not->toBeNull()
        ->and($mensaje['Subject'])->toBe($asunto)
        ->and($mensaje['To'][0]['Address'])->toBe($destino);
});

test('un correo a otra direccion no aparece en la busqueda de un destinatario distinto', function () {
    $res = Http::get($this->mailpit.'/api/v1/search', ['query' => 'to:nadie-'.bin2hex(random_bytes(6)).'@example.test']);

    expect($res->json('messages'))->toBeEmpty();
});
