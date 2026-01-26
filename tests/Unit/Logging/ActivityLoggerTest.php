<?php

use App\Models\Logs\ActivityLog;
use App\Models\User;
use App\Services\Logging\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Auth::login($this->user);
});

test('puede registrar creación de modelo', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::logCreated($user);

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(ActivityLog::ACTION_CREATED)
        ->and($log->model_type)->toBe(User::class)
        ->and($log->model_id)->toBe($user->id)
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values)->toBeArray()
        ->and($log->ip_address)->not->toBeNull();
});

test('puede registrar actualización de modelo', function () {
    $user = User::factory()->create();
    $oldValues = ['name' => 'Old Name', 'email' => 'old@example.com'];

    $log = ActivityLogger::logUpdated($user, $oldValues);

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(ActivityLog::ACTION_UPDATED)
        ->and($log->old_values)->toBeArray()
        ->and($log->new_values)->toBeArray()
        ->and($log->old_values)->toHaveKey('name')
        ->and($log->old_values)->toHaveKey('email');
});

test('puede registrar eliminación de modelo', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::logDeleted($user);

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(ActivityLog::ACTION_DELETED)
        ->and($log->model_type)->toBe(User::class)
        ->and($log->model_id)->toBe($user->id);
});

test('puede registrar restauración de modelo', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::logRestored($user);

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(ActivityLog::ACTION_RESTORED)
        ->and($log->model_type)->toBe(User::class)
        ->and($log->model_id)->toBe($user->id);
});

test('filtra campos sensibles de old_values', function () {
    $user = User::factory()->create();
    $oldValues = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'token' => 'abc123',
    ];

    $log = ActivityLogger::logUpdated($user, $oldValues);

    expect($log->old_values)->not->toHaveKey('password')
        ->and($log->old_values)->not->toHaveKey('password_confirmation')
        ->and($log->old_values)->not->toHaveKey('token')
        ->and($log->old_values)->toHaveKey('name')
        ->and($log->old_values)->toHaveKey('email');
});

test('filtra campos sensibles de new_values', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'secret123',
    ]);

    $log = ActivityLogger::logCreated($user);

    expect($log->new_values)->not->toHaveKey('password')
        ->and($log->new_values)->toHaveKey('name')
        ->and($log->new_values)->toHaveKey('email');
});

test('puede especificar usuario diferente al autenticado', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $log = ActivityLogger::logCreated($user, $otherUser->id);

    expect($log->user_id)->toBe($otherUser->id)
        ->and($log->user_id)->not->toBe($this->user->id);
});

test('usa usuario autenticado por defecto', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::logCreated($user);

    expect($log->user_id)->toBe($this->user->id);
});

test('captura IP address del request', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::logCreated($user);

    expect($log->ip_address)->not->toBeNull()
        ->and($log->ip_address)->toBeString();
});

test('puede agregar campos a la lista de excluidos', function () {
    ActivityLogger::excludeFields(['remember_token', 'email_verified_at']);

    $user = User::factory()->create([
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    $log = ActivityLogger::logCreated($user);

    expect($log->new_values)->not->toHaveKey('remember_token')
        ->and($log->new_values)->not->toHaveKey('email_verified_at')
        ->and($log->new_values)->toHaveKey('name')
        ->and($log->new_values)->toHaveKey('email');
});

test('registra log con acción personalizada', function () {
    $user = User::factory()->create();

    $log = ActivityLogger::log($user, ActivityLog::ACTION_CREATED);

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(ActivityLog::ACTION_CREATED);
});
