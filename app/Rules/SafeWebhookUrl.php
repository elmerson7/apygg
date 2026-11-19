<?php

namespace App\Rules;

use App\Exceptions\BusinessLogicException;
use App\Services\Webhooks\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza URLs de webhook que apuntan a la red interna (SSRF). Ver WebhookUrlGuard.
 */
class SafeWebhookUrl implements ValidationRule
{
    /**
     * La regla se construye con el contenedor (`app(SafeWebhookUrl::class)`) para recibir el guard.
     */
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La URL no es válida.');

            return;
        }

        try {
            $this->guard->inspect($value);
        } catch (BusinessLogicException $e) {
            $fail($e->getMessage());
        }
    }
}
