<?php

use App\Models\Logs\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('puede filtrar por usuario', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    ActivityLog::factory()->forUser($user1->id)->count(3)->create();
    ActivityLog::factory()->forUser($user2->id)->count(2)->create();

    $logs = ActivityLog::byUserId($user1->id)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->user_id === $user1->id))->toBeTrue();
});

test('puede filtrar por tipo de modelo', function () {
    $user = User::factory()->create();
    ActivityLog::factory()->forUser($user->id)->forModel(User::class, \Illuminate\Support\Str::uuid()->toString())->count(3)->create();
    ActivityLog::factory()->forUser($user->id)->forModel(\App\Models\Role::class, \Illuminate\Support\Str::uuid()->toString())->count(2)->create();

    $logs = ActivityLog::byUserId($user->id)->byModelType(User::class)->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->every(fn ($log) => $log->model_type === User::class))->toBeTrue();
});

test('puede filtrar por modelo específico', function () {
    $user = User::factory()->create();
    $targetUser = User::factory()->create();
    $targetUserId = $targetUser->id;

    ActivityLog::factory()->forUser($user->id)->forModel(User::class, $targetUserId)->count(2)->create();
    ActivityLog::factory()->forUser($user->id)->forModel(User::class, \Illuminate\Support\Str::uuid()->toString())->count(3)->create();

    $logs = ActivityLog::byUserId($user->id)->byModel(User::class, $targetUserId)->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($log) => $log->model_id === $targetUserId))->toBeTrue();
});

test('puede filtrar por acción', function () {
    $user = User::factory()->create();
    ActivityLog::factory()->forUser($user->id)->created()->count(2)->create();
    ActivityLog::factory()->forUser($user->id)->updated()->count(3)->create();
    ActivityLog::factory()->forUser($user->id)->deleted()->count(1)->create();

    $createdLogs = ActivityLog::byUserId($user->id)->byAction(ActivityLog::ACTION_CREATED)->get();
    $updatedLogs = ActivityLog::byUserId($user->id)->byAction(ActivityLog::ACTION_UPDATED)->get();

    expect($createdLogs)->toHaveCount(2)
        ->and($updatedLogs)->toHaveCount(3);
});

test('puede usar scopes de acción específicos', function () {
    $user = User::factory()->create();
    ActivityLog::factory()->forUser($user->id)->created()->count(2)->create();
    ActivityLog::factory()->forUser($user->id)->updated()->count(3)->create();
    ActivityLog::factory()->forUser($user->id)->deleted()->count(1)->create();
    ActivityLog::factory()->forUser($user->id)->restored()->count(1)->create();

    $createdCount = ActivityLog::byUserId($user->id)->where('action', ActivityLog::ACTION_CREATED)->count();
    $updatedCount = ActivityLog::byUserId($user->id)->where('action', ActivityLog::ACTION_UPDATED)->count();
    $deletedCount = ActivityLog::byUserId($user->id)->where('action', ActivityLog::ACTION_DELETED)->count();
    $restoredCount = ActivityLog::byUserId($user->id)->where('action', ActivityLog::ACTION_RESTORED)->count();

    expect($createdCount)->toBe(2)
        ->and($updatedCount)->toBe(3)
        ->and($deletedCount)->toBe(1)
        ->and($restoredCount)->toBe(1);
});

test('puede filtrar por rango de fechas', function () {
    $startDate = now()->subDays(5);
    $endDate = now()->subDays(2);

    ActivityLog::factory()->create(['created_at' => now()->subDays(6)]);
    ActivityLog::factory()->create(['created_at' => now()->subDays(4)]);
    ActivityLog::factory()->create(['created_at' => now()->subDays(3)]);
    ActivityLog::factory()->create(['created_at' => now()->subDay()]);

    $logs = ActivityLog::dateRange($startDate->toDateString(), $endDate->toDateString())->get();

    expect($logs)->toHaveCount(2);
});

test('puede ordenar por más recientes', function () {
    ActivityLog::factory()->create(['created_at' => now()->subDays(3)]);
    ActivityLog::factory()->create(['created_at' => now()->subDays(1)]);
    ActivityLog::factory()->create(['created_at' => now()->subDays(2)]);

    $logs = ActivityLog::recent()->get();

    expect($logs->first()->created_at->gt($logs->last()->created_at))->toBeTrue();
});

test('puede obtener campos que cambiaron', function () {
    $log = ActivityLog::factory()->updated()->create([
        'old_values' => ['name' => 'Old Name', 'email' => 'old@example.com'],
        'new_values' => ['name' => 'New Name', 'email' => 'new@example.com'],
    ]);

    $changedFields = $log->getChangedFields();

    expect($changedFields)->toHaveKey('name')
        ->and($changedFields)->toHaveKey('email')
        ->and($changedFields['name']['old'])->toBe('Old Name')
        ->and($changedFields['name']['new'])->toBe('New Name');
});

test('retorna array vacío si no hay cambios', function () {
    $log = ActivityLog::factory()->created()->create([
        'old_values' => null,
        'new_values' => ['name' => 'Test'],
    ]);

    $changedFields = $log->getChangedFields();

    expect($changedFields)->toBeArray()
        ->and($changedFields)->toBeEmpty();
});

test('puede verificar si hubo cambios en campos', function () {
    $logWithChanges = ActivityLog::factory()->updated()->create([
        'old_values' => ['name' => 'Old'],
        'new_values' => ['name' => 'New'],
    ]);

    $logWithoutChanges = ActivityLog::factory()->created()->create([
        'old_values' => null,
        'new_values' => ['name' => 'Test'],
    ]);

    expect($logWithChanges->hasFieldChanges())->toBeTrue()
        ->and($logWithoutChanges->hasFieldChanges())->toBeFalse();
});

test('tiene relación con usuario', function () {
    $user = User::factory()->create();
    $log = ActivityLog::factory()->forUser($user->id)->create();

    expect($log->user)->not->toBeNull()
        ->and($log->user->id)->toBe($user->id);
});

test('tiene relación polimórfica con modelo auditado', function () {
    $user = User::factory()->create();
    $log = ActivityLog::factory()->forModel(User::class, $user->id)->create();

    expect($log->model)->not->toBeNull()
        ->and($log->model->id)->toBe($user->id);
});
