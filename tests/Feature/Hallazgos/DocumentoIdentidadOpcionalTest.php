<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->admin = User::factory()->admin()->create();
    $this->token = JWTAuth::fromUser($this->admin);
    $this->rol = Role::where('name', 'user')->firstOrFail();
});

/** @param array<string, mixed> $extra */
function usuarioNuevo(object $t, array $extra = []): TestResponse
{
    static $n = 0;
    $n++;

    return $t->withHeader('Authorization', "Bearer {$t->token}")->postJson('/users', array_merge([
        'first_name' => 'Ana',
        'last_name' => 'Prueba',
        'username' => "ana{$n}",
        'email' => "ana{$n}@example.com",
        'password' => 'Password123!',
        'role_ids' => [$t->rol->id],
    ], $extra));
}

test('crea un usuario sin documento de identidad', function () {
    usuarioNuevo($this)->assertCreated();

    expect(User::where('username', 'ana1')->first()->identity_document)->toBeNull();
});

test('acepta cualquier formato de documento cuando el proyecto no fija una regla', function () {
    usuarioNuevo($this, ['identity_document' => 'X-1234567-Z'])->assertCreated();

    $this->assertDatabaseHas('users', ['identity_document' => 'X-1234567-Z']);
});

test('rechaza un documento repetido cuando viene informado', function () {
    usuarioNuevo($this, ['identity_document' => 'DOC-1'])->assertCreated();

    usuarioNuevo($this, ['identity_document' => 'DOC-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document']);
});

test('varios usuarios sin documento conviven', function () {
    usuarioNuevo($this)->assertCreated();
    usuarioNuevo($this, ['identity_document' => ''])->assertCreated();

    expect(User::whereNull('identity_document')->count())->toBeGreaterThanOrEqual(2);
});

test('aplica la regla configurada por el proyecto en el alta y en la edición', function () {
    config(['users.identity_document.regex' => '/^[0-9]{8}$/']);

    usuarioNuevo($this, ['identity_document' => 'ABC'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document']);

    usuarioNuevo($this, ['identity_document' => '12345678'])->assertCreated();

    $otro = User::factory()->create();

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/users/{$otro->id}", ['identity_document' => 'mal'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['identity_document']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/users/{$otro->id}", ['identity_document' => '87654321'])
        ->assertOk();
});

test('la edición permite borrar el documento y no admite el de otro usuario', function () {
    $a = User::factory()->create(['identity_document' => 'AAA']);
    $b = User::factory()->create(['identity_document' => 'BBB']);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/users/{$b->id}", ['identity_document' => 'AAA'])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/users/{$b->id}", ['identity_document' => null])
        ->assertOk();

    expect($b->fresh()->identity_document)->toBeNull();
});
