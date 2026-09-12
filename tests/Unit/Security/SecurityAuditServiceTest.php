<?php

use App\Models\SecurityScan;
use App\Services\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SecurityAuditService::class);
    $this->scan = SecurityScan::create([
        'status' => SecurityScan::STATUS_PENDING,
        'salt' => SecurityScan::generateSalt(),
    ]);
});

test('la anonimización hashea los campos sensibles', function () {
    $findings = [[
        'check' => 'security_logs',
        'severity' => 'high',
        'evidence' => [
            ['ip_address' => '203.0.113.9', 'count' => 12],
        ],
    ]];

    $result = $this->service->anonymize($findings, $this->scan);
    $evidence = $result[0]['evidence'][0];

    expect($evidence['ip_address'])->toStartWith('h_')
        ->and($evidence['ip_address'])->not->toContain('203.0.113.9')
        // Los conteos y las rutas siguen siendo reales: son lo que el modelo analiza
        ->and($evidence['count'])->toBe(12);
});

test('el mismo valor produce el mismo hash dentro de un escaneo', function () {
    $findings = [[
        'evidence' => [
            ['ip_address' => '203.0.113.9'],
            ['ip_address' => '203.0.113.9'],
        ],
    ]];

    $result = $this->service->anonymize($findings, $this->scan);

    expect($result[0]['evidence'][0]['ip_address'])
        ->toBe($result[0]['evidence'][1]['ip_address']);
});

test('escaneos distintos producen hashes distintos para el mismo valor', function () {
    $other = SecurityScan::create([
        'status' => SecurityScan::STATUS_PENDING,
        'salt' => SecurityScan::generateSalt(),
    ]);

    $findings = [['evidence' => [['ip_address' => '203.0.113.9']]]];

    expect($this->service->anonymize($findings, $this->scan)[0]['evidence'][0]['ip_address'])
        ->not->toBe($this->service->anonymize($findings, $other)[0]['evidence'][0]['ip_address']);
});

test('los campos no sensibles no se tocan', function () {
    $findings = [['evidence' => [['path' => '/admin/users', 'count' => 3]]]];

    expect($this->service->anonymize($findings, $this->scan)[0]['evidence'][0])
        ->toBe(['path' => '/admin/users', 'count' => 3]);
});

test('worstSeverity escala pero nunca baja', function () {
    expect($this->service->worstSeverity('ok', 'high'))->toBe('high')
        ->and($this->service->worstSeverity('critical', 'low'))->toBe('critical')
        ->and($this->service->worstSeverity('medium', 'medium'))->toBe('medium')
        ->and($this->service->worstSeverity('low', 'desconocida'))->toBe('low');
});

test('la severidad local es la peor de los hallazgos', function () {
    expect($this->service->localSeverity([
        ['severity' => 'ok'],
        ['severity' => 'medium'],
        ['severity' => 'critical'],
        ['severity' => 'low'],
    ]))->toBe('critical');

    expect($this->service->localSeverity([]))->toBe('ok');
});

test('la recolección devuelve todos los chequeos con su forma esperada', function () {
    $findings = $this->service->collectFindings();

    expect($findings)->not->toBeEmpty();

    foreach ($findings as $finding) {
        expect($finding)->toHaveKeys(['check', 'label', 'severity', 'status', 'detail', 'evidence']);
    }

    expect(collect($findings)->pluck('check')->all())
        ->toContain('environment', 'security_logs', 'api_errors', 'activity_logs', 'recent_users', 'api_keys');
});

test('APP_DEBUG en producción se marca como crítico', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', true);

    $environment = collect($this->service->collectFindings())->firstWhere('check', 'environment');

    expect($environment['severity'])->toBe('critical')
        ->and($environment['detail'])->toContain('APP_DEBUG');
});

test('la sal nunca se serializa', function () {
    expect($this->scan->toArray())->not->toHaveKey('salt');
});
