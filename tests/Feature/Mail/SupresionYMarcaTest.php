<?php

use App\Contracts\BrandResolverInterface;
use App\Contracts\MailSuppressionServiceInterface;
use App\Contracts\SettingsRegistryInterface;
use App\Exceptions\SettingException;
use App\Integrations\Webhooks\Handlers\ResendMailEventsHandler;
use App\Models\InboundWebhookReceipt;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailLog;
use App\Models\Mail\MailProvider;
use App\Models\Mail\MailSuppression;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ActsWithHeaders;
use Tests\Support\AssertsTenantIsolation;
use Tests\Support\InteractsWithTenancy;

uses(RefreshDatabase::class, InteractsWithTenancy::class, ActsWithHeaders::class, AssertsTenantIsolation::class);

beforeEach(function () {
    config(['mail.fallback' => 'array', 'queue.default' => 'sync', 'mail-system.block_reserved_domains' => true]);
    $this->bootIsolation();

    $provider = MailProvider::firstOrCreate(['driver' => 'array'], ['name' => 'Array', 'is_active' => true, 'sort_order' => 0]);
    MailAccount::create([
        'name' => 'Principal', 'mail_provider_id' => $provider->id, 'from_email' => 'hola@ejemplo.com',
        'from_name' => 'Equipo', 'is_active' => true, 'is_default' => true, 'priority' => 0,
    ]);
});

function sentTo(): array
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all())
        ->flatMap(fn ($m) => collect($m->getOriginalMessage()->getTo())->map->getAddress())
        ->all();
}

function usuarioMail(string $email): User
{
    return User::factory()->create(['email' => $email]);
}

function eventoResend(string $type, array $data, string $delivery = 'msg_1'): void
{
    $receipt = (new InboundWebhookReceipt)->forceFill([
        'provider' => 'resend',
        'delivery_id' => $delivery,
        'payload' => ['type' => $type, 'created_at' => now()->toIso8601String(), 'data' => $data + ['email_id' => 'em_'.$delivery]],
    ]);

    app(ResendMailEventsHandler::class)->handle($receipt);
}

// ── Alta por eventos de Resend ─────────────────────────────────────────────

test('un rebote permanente suprime la dirección normalizada', function () {
    eventoResend('email.bounced', ['to' => ['  Ana@Cliente.COM '], 'bounce' => ['type' => 'Permanent', 'subType' => 'General']]);

    $row = MailSuppression::first();
    expect($row->email)->toBe('ana@cliente.com')
        ->and($row->reason)->toBe('bounce')
        ->and($row->source)->toBe('resend')
        ->and($row->details['bounce_sub_type'])->toBe('General');
});

test('un rebote blando o indeterminado no suprime', function () {
    eventoResend('email.bounced', ['to' => ['a@cliente.com'], 'bounce' => ['type' => 'Transient']], 'd1');
    eventoResend('email.bounced', ['to' => ['b@cliente.com'], 'bounce' => ['type' => 'Undetermined']], 'd2');
    eventoResend('email.delivered', ['to' => ['c@cliente.com']], 'd3');

    expect(MailSuppression::count())->toBe(0);
});

test('una queja suprime y sustituye a un rebote previo; repetir el evento no duplica', function () {
    eventoResend('email.bounced', ['to' => ['ana@cliente.com'], 'bounce' => ['type' => 'Permanent']], 'd1');
    eventoResend('email.complained', ['to' => ['ana@cliente.com']], 'd2');
    eventoResend('email.complained', ['to' => ['ana@cliente.com']], 'd2');

    expect(MailSuppression::count())->toBe(1)
        ->and(MailSuppression::first()->reason)->toBe('complaint');
});

test('un rebote posterior no degrada una queja ni una supresión manual', function () {
    app(MailSuppressionServiceInterface::class)->suppress('ana@cliente.com', 'manual', 'admin');
    eventoResend('email.bounced', ['to' => ['ana@cliente.com'], 'bounce' => ['type' => 'Permanent']]);

    expect(MailSuppression::first()->reason)->toBe('manual');
});

// ── Envío ──────────────────────────────────────────────────────────────────

test('un correo a una dirección suprimida se descarta y queda como skipped', function () {
    eventoResend('email.complained', ['to' => ['ana@cliente.com']]);

    usuarioMail('Ana@Cliente.com')->notify(new WelcomeNotification);

    $log = MailLog::first();
    expect(sentTo())->toBe([])
        ->and($log->status)->toBe(MailLog::STATUS_SKIPPED)
        ->and(strtolower($log->to_email))->toBe('ana@cliente.com')
        ->and($log->error_message)->toContain('suprimido');
});

test('una dirección no suprimida sigue recibiendo el correo', function () {
    eventoResend('email.complained', ['to' => ['otra@cliente.com']]);

    usuarioMail('ana@cliente.com')->notify(new WelcomeNotification);

    expect(sentTo())->toBe(['ana@cliente.com'])
        ->and(MailLog::first()->status)->toBe(MailLog::STATUS_SENT);
});

test('con varios destinatarios solo se descarta el suprimido', function () {
    eventoResend('email.complained', ['to' => ['mala@cliente.com']]);

    Mail::raw('hola', fn ($m) => $m->to(['mala@cliente.com', 'buena@cliente.com'])->subject('Aviso'));

    expect(sentTo())->toBe(['buena@cliente.com'])
        ->and(MailLog::where('status', MailLog::STATUS_SKIPPED)->pluck('to_email')->all())->toBe(['mala@cliente.com'])
        ->and(MailLog::where('status', MailLog::STATUS_SENT)->count())->toBe(1);
});

test('el reset de contraseña ignora un rebote pero respeta una queja', function () {
    $rebotada = usuarioMail('rebota@cliente.com');
    $quejosa = usuarioMail('queja@cliente.com');
    eventoResend('email.bounced', ['to' => ['rebota@cliente.com'], 'bounce' => ['type' => 'Permanent']], 'd1');
    eventoResend('email.complained', ['to' => ['queja@cliente.com']], 'd2');

    $rebotada->notify(new ResetPasswordNotification('tok'));
    $quejosa->notify(new ResetPasswordNotification('tok'));

    expect(sentTo())->toBe(['rebota@cliente.com']);
});

test('la lista de críticos es configurable', function () {
    config(['mail-system.suppression.critical_templates' => []]);
    $u = usuarioMail('rebota@cliente.com');
    eventoResend('email.bounced', ['to' => ['rebota@cliente.com']] + ['bounce' => ['type' => 'Permanent']]);

    $u->notify(new ResetPasswordNotification('tok'));

    expect(sentTo())->toBe([]);
});

test('con la supresión apagada se envía igual', function () {
    config(['mail-system.suppression.enabled' => false]);
    eventoResend('email.complained', ['to' => ['ana@cliente.com']]);

    usuarioMail('ana@cliente.com')->notify(new WelcomeNotification);

    expect(sentTo())->toBe(['ana@cliente.com']);
});

// ── Dominios reservados ────────────────────────────────────────────────────

test('nunca se envía a dominios reservados y se registra como descartado', function (string $email) {
    usuarioMail($email)->notify(new WelcomeNotification);

    expect(sentTo())->toBe([])
        ->and(MailLog::first()->status)->toBe(MailLog::STATUS_SKIPPED)
        ->and(MailLog::first()->error_message)->toContain('reservado');
})->with(['ana@example.com', 'ana@sub.example.org', 'ana@empresa.test', 'ana@host.invalid', 'ana@mi.localhost', 'ana@algo.example']);

test('un dominio parecido pero no reservado sí recibe correo', function () {
    usuarioMail('ana@noexample.com')->notify(new WelcomeNotification);
    usuarioMail('ana@contest.io')->notify(new WelcomeNotification);

    expect(sentTo())->toBe(['ana@noexample.com', 'ana@contest.io']);
});

test('los dominios reservados se pueden permitir por configuración', function () {
    config(['mail-system.block_reserved_domains' => false]);

    usuarioMail('ana@example.com')->notify(new WelcomeNotification);

    expect(sentTo())->toBe(['ana@example.com']);
});

test('los dominios reservados no se saltan ni en correos críticos', function () {
    usuarioMail('ana@example.com')->notify(new ResetPasswordNotification('tok'));

    expect(sentTo())->toBe([]);
});

// ── Endpoints admin ────────────────────────────────────────────────────────

function adminMail(string ...$permisos): array
{
    $user = User::factory()->create();

    foreach ($permisos as $p) {
        $user->permissions()->attach(Permission::where('name', $p)->value('id'));
    }

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

test('quien tiene mail.suppressions lista, añade y quita direcciones', function () {
    $h = adminMail('mail.suppressions');
    eventoResend('email.complained', ['to' => ['ana@cliente.com']]);

    $this->asHeaders($h)->getJson('/admin/mail/suppressions?reason=complaint')
        ->assertOk()->assertJsonPath('data.0.email', 'ana@cliente.com')->assertJsonPath('meta.total', 1);

    $this->asHeaders($h)->postJson('/admin/mail/suppressions', ['email' => 'Nuevo@Cliente.com', 'note' => 'pidió baja'])
        ->assertCreated()->assertJsonPath('data.email', 'nuevo@cliente.com')->assertJsonPath('data.reason', 'manual');

    $id = MailSuppression::where('email', 'ana@cliente.com')->value('id');
    $this->asHeaders($h)->deleteJson("/admin/mail/suppressions/{$id}")->assertOk();

    expect(MailSuppression::pluck('email')->all())->toBe(['nuevo@cliente.com']);

    // Quitada, vuelve a recibir
    usuarioMail('ana@cliente.com')->notify(new WelcomeNotification);
    expect(sentTo())->toBe(['ana@cliente.com']);
});

test('sin mail.suppressions (aunque tenga mail.manage) se deniega todo', function () {
    $h = adminMail('mail.manage', 'mail.logs');
    $row = app(MailSuppressionServiceInterface::class)->suppress('ana@cliente.com', 'manual', 'admin');

    $this->getJson('/admin/mail/suppressions')->assertUnauthorized();
    $this->asHeaders($h)->getJson('/admin/mail/suppressions')->assertForbidden();
    $this->asHeaders($h)->postJson('/admin/mail/suppressions', ['email' => 'x@cliente.com'])->assertForbidden();
    $this->asHeaders($h)->deleteJson("/admin/mail/suppressions/{$row->id}")->assertForbidden();
    expect(MailSuppression::count())->toBe(1);
});

test('añadir una dirección inválida da 422', function () {
    $this->asHeaders(adminMail('mail.suppressions'))->postJson('/admin/mail/suppressions', ['email' => 'no-es-correo'])->assertUnprocessable();
});

test('la supresión es global: se aplica igual en cualquier tenant', function () {
    eventoResend('email.complained', ['to' => ['ana@cliente.com']]);
    $ana = usuarioMail('ana@cliente.com');

    $this->ctx->run($this->a, fn () => $ana->notify(new WelcomeNotification));
    $this->ctx->run($this->b, fn () => $ana->notify(new WelcomeNotification));

    expect(sentTo())->toBe([])
        ->and(MailLog::where('status', MailLog::STATUS_SKIPPED)->count())->toBe(2);
});

// ── Marca ──────────────────────────────────────────────────────────────────

test('sin ajustes la marca sale de config/brand.php', function () {
    config(['brand.name' => 'Plataforma', 'brand.legal_address' => 'Calle 1', 'brand.colors.primary' => '#111111']);

    $brand = app(BrandResolverInterface::class)->get();

    expect($brand['name'])->toBe('Plataforma')
        ->and($brand['legal_address'])->toBe('Calle 1')
        ->and($brand['colors']['primary'])->toBe('#111111')
        ->and($brand['logo_url'])->toBeNull();
});

test('la marca de plataforma (ajustes) pisa a la config y la del tenant a la de plataforma', function () {
    config(['brand.name' => 'Config']);
    $registry = app(SettingsRegistryInterface::class);
    $adminA = $this->isoUser($this->a, 'admin');

    $registry->setPlatform('brand.name', 'Plataforma SA');
    $registry->setPlatform('brand.primary_color', '#abcdef');
    $this->ctx->run($this->a, fn () => $registry->setTenant($adminA, 'brand.name', 'Acme Corp'));

    $resolver = app(BrandResolverInterface::class);

    expect($resolver->get()['name'])->toBe('Plataforma SA');
    $this->ctx->run($this->a, function () use ($resolver) {
        expect($resolver->get()['name'])->toBe('Acme Corp')
            ->and($resolver->get()['colors']['primary'])->toBe('#abcdef'); // lo no sobrescrito cae a plataforma
    });
    $this->ctx->run($this->b, fn () => expect($resolver->get()['name'])->toBe('Plataforma SA'));
});

test('el correo usa la marca del tenant activo y no la de otro', function () {
    $registry = app(SettingsRegistryInterface::class);
    $adminA = $this->isoUser($this->a, 'admin');
    $this->ctx->run($this->a, function () use ($registry, $adminA) {
        $registry->setTenant($adminA, 'brand.name', 'Acme Corp');
        $registry->setTenant($adminA, 'brand.logo_url', 'https://cdn.acme.com/logo.png');
        $registry->setTenant($adminA, 'brand.legal_address', 'Av. Acme 123');
        $registry->setTenant($adminA, 'brand.social', ['twitter' => 'https://twitter.com/acme', 'mal' => 'javascript:alert(1)']);
    });
    config(['brand.name' => 'Plataforma']);

    $render = fn () => (string) (new ResetPasswordNotification('tok'))->toMail(User::factory()->create())->render();

    $htmlA = $this->ctx->run($this->a, $render);
    $htmlB = $this->ctx->run($this->b, $render);

    expect($htmlA)->toContain('Acme Corp')->toContain('https://cdn.acme.com/logo.png')->toContain('Av. Acme 123')
        ->toContain('https://twitter.com/acme')->not->toContain('javascript:')
        ->and($htmlB)->toContain('Plataforma')->not->toContain('Acme Corp')->not->toContain('cdn.acme.com');
});

test('un tenant no puede guardar una marca inválida', function () {
    $registry = app(SettingsRegistryInterface::class);
    $adminA = $this->isoUser($this->a, 'admin');

    $this->ctx->run($this->a, function () use ($registry, $adminA) {
        expect(fn () => $registry->setTenant($adminA, 'brand.primary_color', 'rojo'))->toThrow(SettingException::class)
            ->and(fn () => $registry->setTenant($adminA, 'brand.logo_url', 'http://inseguro.com/x.png'))->toThrow(SettingException::class);
    });
});
