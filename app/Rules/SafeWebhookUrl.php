<?php

namespace App\Rules;

use App\Services\Webhooks\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Rechaza URLs de webhook que apuntan a la red interna (SSRF). Ver WebhookUrlGuard.
 */
class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La URL no es válida.');

            return;
        }

        try {
            WebhookUrlGuard::inspect($value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
