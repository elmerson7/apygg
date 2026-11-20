<?php

namespace App\Integrations;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\RateLimitedError;
use Closure;

/**
 * Reintento de una llamada síncrona a una integración.
 *
 * Solo reintenta los errores que dicen `isRetryable()` (Transient y RateLimited). Los demás
 * (Permanent, QuotaExceeded, InvalidOutput) y cualquier otra excepción suben tal cual.
 * Espera: `retry_after` del proveedor si lo hay (con tope), o backoff exponencial con jitter.
 *
 * No tiene estado entre llamadas. El `sleeper` es inyectable para probarlo sin dormir.
 * Dentro de un job usa los reintentos de la cola (`release()`), no esto (docs/queues-and-jobs.md).
 */
class RetryPolicy
{
    /**
     * @param  Closure(int): void|null  $sleeper  recibe milisegundos
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5000,
        private readonly int $maxRetryAfterMs = 30000,
        private readonly ?Closure $sleeper = null,
    ) {}

    public static function fromConfig(?Closure $sleeper = null): self
    {
        return new self(
            max(1, (int) config('integrations.defaults.retry.max_attempts', 3)),
            (int) config('integrations.defaults.retry.base_delay_ms', 200),
            (int) config('integrations.defaults.retry.max_delay_ms', 5000),
            (int) config('integrations.defaults.retry.max_retry_after_ms', 30000),
            $sleeper,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(int): T  $call  recibe el nº de intento (1...)
     * @return T
     */
    public function run(Closure $call): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $call($attempt);
            } catch (IntegrationException $e) {
                if (! $e->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $this->sleep($this->delayMs($e, $attempt));
                $attempt++;
            }
        }
    }

    /** Espera (ms) antes del siguiente intento, tras fallar el nº `$attempt`. */
    public function delayMs(IntegrationException $e, int $attempt): int
    {
        if ($e instanceof RateLimitedError && $e->retryAfter() !== null) {
            return min($e->retryAfter() * 1000, $this->maxRetryAfterMs);
        }

        $exponential = min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);

        // Jitter completo en la mitad superior: evita que todos los workers reintenten a la vez.
        return random_int((int) ($exponential / 2), $exponential);
    }

    private function sleep(int $ms): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }

        usleep($ms * 1000);
    }
}
