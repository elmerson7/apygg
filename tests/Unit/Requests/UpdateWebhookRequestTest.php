<?php

use App\Enums\WebhookStatusEnum;
use App\Http\Requests\Webhooks\UpdateWebhookRequest;
use Illuminate\Support\Facades\Validator;

it('acepta los estados válidos de webhook al actualizar', function (string $status) {
    $rules = (new UpdateWebhookRequest)->rules();

    expect(Validator::make(['status' => $status], ['status' => $rules['status']])->passes())->toBeTrue();
})->with(fn () => array_map(fn ($c) => $c->value, WebhookStatusEnum::cases()));

it('rechaza un estado de webhook desconocido al actualizar', function () {
    $rules = (new UpdateWebhookRequest)->rules();

    expect(Validator::make(['status' => 'borrado'], ['status' => $rules['status']])->fails())->toBeTrue();
});
