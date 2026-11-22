<?php

use App\Enums\WebhookStatusEnum;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('precalienta la caché sin fallar cuando hay webhooks activos', function () {
    $user = User::factory()->create();
    Webhook::create([
        'user_id' => $user->id,
        'name' => 'Webhook de prueba',
        'url' => 'https://example.com/hook',
        'secret' => 'secreto',
        'events' => ['user.created'],
        'status' => WebhookStatusEnum::active->value,
    ]);

    $this->artisan('cache:warm')->assertSuccessful();
});
