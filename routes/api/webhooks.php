<?php

use Illuminate\Support\Facades\Route;

Route::post('webhooks/stripe', [WebhookController::class, 'stripe'])->name('webhooks.stripe');
# Stripe/Twilio/etc.