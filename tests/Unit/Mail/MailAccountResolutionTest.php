<?php

use App\Models\Mail\MailAccount;
use App\Models\Mail\MailProvider;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAccount(array $attributes = []): MailAccount
{
    return MailAccount::create(array_merge([
        'name' => 'Cuenta '.uniqid(),
        'from_email' => uniqid().'@ejemplo.com',
        'from_name' => 'Remitente',
        'is_active' => true,
        'is_default' => false,
        'priority' => 0,
    ], $attributes));
}

test('elige la cuenta configurada para la plantilla por encima de la predeterminada', function () {
    $default = makeAccount(['is_default' => true]);
    $specific = makeAccount(['priority' => 99]);

    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'mail_account_id' => $specific->id,
        'is_active' => true,
    ]);

    expect(MailAccount::getActiveForSending('welcome')->id)->toBe($specific->id)
        ->and(MailAccount::getActiveForSending()->id)->toBe($default->id);
});

test('ignora la cuenta de la plantilla si está inactiva y cae en la predeterminada', function () {
    $default = makeAccount(['is_default' => true]);
    $inactive = makeAccount(['is_active' => false]);

    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'mail_account_id' => $inactive->id,
        'is_active' => true,
    ]);

    expect(MailAccount::getActiveForSending('welcome')->id)->toBe($default->id);
});

test('sin cuenta predeterminada usa la activa de menor prioridad', function () {
    makeAccount(['priority' => 10]);
    $first = makeAccount(['priority' => 1]);

    expect(MailAccount::getActiveForSending()->id)->toBe($first->id);
});

test('devuelve null cuando no hay ninguna cuenta activa', function () {
    makeAccount(['is_active' => false]);

    expect(MailAccount::getActiveForSending())->toBeNull();
});

test('el fallback excluye el proveedor indicado', function () {
    $resend = MailProvider::create(['name' => 'Resend', 'driver' => 'resend', 'is_active' => true]);
    $smtp = MailProvider::create(['name' => 'SMTP', 'driver' => 'smtp', 'is_active' => true]);

    makeAccount(['mail_provider_id' => $resend->id, 'priority' => 0]);
    $smtpAccount = makeAccount(['mail_provider_id' => $smtp->id, 'priority' => 5]);

    expect(MailAccount::getFallbackForSending($resend->id)->id)->toBe($smtpAccount->id);
});

test('el driver sale del proveedor asociado', function () {
    $provider = MailProvider::create(['name' => 'SMTP', 'driver' => 'smtp', 'is_active' => true]);
    $account = makeAccount(['mail_provider_id' => $provider->id]);

    expect($account->fresh()->getDriver())->toBe('smtp')
        ->and($account->fresh()->isResend())->toBeFalse();
});
