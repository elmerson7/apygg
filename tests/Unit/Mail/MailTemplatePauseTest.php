<?php

use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('una plantilla activa no está pausada', function () {
    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'is_active' => true,
    ]);

    expect(MailTemplateConfig::isPaused('welcome'))->toBeFalse();
});

test('una plantilla inactiva está pausada', function () {
    MailTemplateConfig::create([
        'template_key' => 'welcome',
        'template_name' => 'Bienvenida',
        'is_active' => false,
    ]);

    expect(MailTemplateConfig::isPaused('welcome'))->toBeTrue();
});

test('una plantilla no registrada no se considera pausada', function () {
    expect(MailTemplateConfig::isPaused('inexistente'))->toBeFalse();
});

test('seedDefaults es idempotente', function () {
    MailTemplateConfig::seedDefaults();
    $first = MailTemplateConfig::count();

    MailTemplateConfig::seedDefaults();

    expect(MailTemplateConfig::count())->toBe($first)
        ->and($first)->toBeGreaterThan(0);
});

test('la pausa global se lee como booleano', function () {
    expect(MailSetting::isGloballyPaused())->toBeFalse();

    MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, true);

    expect(MailSetting::isGloballyPaused())->toBeTrue();

    MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, false);

    expect(MailSetting::isGloballyPaused())->toBeFalse();
});

test('los límites se leen como entero', function () {
    MailSetting::setValue(MailSetting::KEY_DAILY_LIMIT, 500);

    expect(MailSetting::getValue(MailSetting::KEY_DAILY_LIMIT))->toBe(500)
        ->and(MailSetting::getValue(MailSetting::KEY_RATE_LIMIT, 0))->toBe(0);
});
