<?php

use App\Http\Controllers\Webhooks\InboundWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks entrantes (docs/integrations.md §7)
|--------------------------------------------------------------------------
|
| Públicos: los llama el proveedor y los protege la FIRMA (más el rate limit `inbound-webhooks`),
| no el login. Cada proveedor se registra en `config/integrations.php`.
|
*/

Route::post('/webhooks/inbound/{provider}', [InboundWebhookController::class, 'receive'])
    ->where('provider', '[a-z0-9_-]+')
    ->middleware('throttle:inbound-webhooks')
    ->name('webhooks.inbound');

// Alias histórico del webhook de Resend (la URL ya está configurada en su panel): no la cambies.
Route::post('/webhooks/mail', [InboundWebhookController::class, 'receive'])
    ->defaults('provider', 'resend')
    ->middleware('throttle:inbound-webhooks')
    ->name('webhooks.mail');
