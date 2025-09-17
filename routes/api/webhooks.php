<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['webhook.security'])->group(function () {
    Route::post('webhooks/stripe', [WebhookController::class, 'stripe'])->name('webhooks.stripe');
    # Stripe/Twilio/etc.
});