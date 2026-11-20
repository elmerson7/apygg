<?php

namespace App\Integrations\Exceptions;

use Throwable;

/** HTTP 429 del proveedor. `retryAfter` (segundos) manda sobre el backoff propio. */
class RateLimitedError extends IntegrationException
{
    public function __construct(string $message = '', ?string $service = null, private readonly ?int $retryAfter = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $service, $previous);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
