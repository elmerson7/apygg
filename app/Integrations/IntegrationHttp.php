<?php

namespace App\Integrations;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\PermanentIntegrationError;
use App\Integrations\Exceptions\QuotaExceededError;
use App\Integrations\Exceptions\RateLimitedError;
use App\Integrations\Exceptions\TransientIntegrationError;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de las integraciones: timeouts por defecto SIEMPRE y traducción de errores.
 *
 * Sin timeout, una integración lenta bloquea un worker de Octane o de Horizon. El timeout
 * debe ser menor que el `timeout` del job que la llama.
 */
class IntegrationHttp
{
    public static function client(string $service): PendingRequest
    {
        return Http::timeout((int) config("integrations.{$service}.http.timeout", config('integrations.defaults.timeout', 10)))
            ->connectTimeout((int) config('integrations.defaults.connect_timeout', 3))
            ->acceptJson();
    }

    /**
     * Ejecuta la petición y devuelve la respuesta EXITOSA, o lanza el error tipado que corresponda.
     * Conexión/timeout -> Transient; 429 -> RateLimited; 402 -> Quota; 5xx/408 -> Transient; otros 4xx -> Permanent.
     *
     * @param  Closure(): Response  $request
     *
     * @throws IntegrationException
     */
    public static function send(string $service, Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new TransientIntegrationError('Error de conexión con el servicio', $service, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw self::errorFor($service, $response);
    }

    public static function errorFor(string $service, Response $response): IntegrationException
    {
        $status = $response->status();
        $message = "{$service}: el proveedor respondió HTTP {$status}";

        return match (true) {
            $status === 429 => new RateLimitedError($message, $service, self::retryAfter($response)),
            $status === 402 => new QuotaExceededError($message, $service),
            $status === 408 || $status >= 500 => new TransientIntegrationError($message, $service),
            default => new PermanentIntegrationError($message, $service),
        };
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
