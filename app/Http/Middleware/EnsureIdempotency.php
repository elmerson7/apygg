<?php

namespace App\Http\Middleware;

use App\Contracts\IdempotencyInterface;
use App\Exceptions\IdempotencyException;
use App\Support\Idempotency\Reservation;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Alias `idempotent` / `idempotent:required` (docs/idempotency.md). Va DESPUÉS de `auth:api` y `tenant`.
 *
 * Lee `Idempotency-Key` y, con la misma clave del mismo actor y tenant: repite la respuesta guardada
 * (`Idempotent-Replayed: true`), 422 si cambió la petición, 409 si sigue en curso. Una respuesta 5xx (o una
 * excepción) libera la clave para poder reintentar. Sin cabecera: pasa de largo (opcional) o 400 (`required`).
 */
class EnsureIdempotency
{
    /** Estados que nunca se guardan: el reintento debe poder ejecutarse. */
    private const NEVER_STORED = [408, 425, 429];

    public function __construct(
        private readonly IdempotencyInterface $idempotency,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): SymfonyResponse
    {
        $methods = array_map('strtoupper', (array) config('idempotency.methods', ['POST', 'PATCH']));

        if (! config('idempotency.enabled', true) || ! in_array($request->method(), $methods, true)) {
            return $next($request);
        }

        $header = (string) config('idempotency.header', 'Idempotency-Key');
        $key = $request->header($header);

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw IdempotencyException::required($header);
            }

            return $next($request);
        }

        if (strlen($key) > (int) config('idempotency.max_key_length', 255) || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            throw IdempotencyException::invalid($header);
        }

        $reservation = $this->idempotency->begin(
            $this->tenant->id(),
            $this->actor($request),
            $key,
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            $this->fingerprint($request),
        );

        return match ($reservation->outcome) {
            Reservation::REPLAY => $this->replay($reservation),
            Reservation::MISMATCH => throw IdempotencyException::mismatch(),
            Reservation::IN_PROGRESS => throw IdempotencyException::inProgress(),
            default => $this->execute($request, $next, $reservation),
        };
    }

    private function execute(Request $request, Closure $next, Reservation $reservation): SymfonyResponse
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->release($reservation);

            throw $e;
        }

        $body = $response->getContent();
        $status = $response->getStatusCode();

        if ($body === false || ! $this->shouldStore($status) || strlen($body) > (int) config('idempotency.max_response_bytes', 65536)) {
            $this->idempotency->release($reservation);

            return $response;
        }

        $this->idempotency->complete($reservation, $status, $this->storableHeaders($response), $body);

        return $response;
    }

    private function replay(Reservation $reservation): SymfonyResponse
    {
        $response = new Response((string) $reservation->body, (int) $reservation->status);

        foreach ($reservation->headers as $name => $values) {
            $response->headers->set($name, array_map(fn (?string $value): string => (string) $value, $values));
        }

        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function shouldStore(int $status): bool
    {
        if ($status >= 500 || in_array($status, self::NEVER_STORED, true)) {
            return false;
        }

        if ($status >= 400) {
            return (bool) config('idempotency.store_client_errors', true);
        }

        return $status >= 200;
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function storableHeaders(SymfonyResponse $response): array
    {
        $denied = array_map('strtolower', (array) config('idempotency.sensitive_headers', []));
        $headers = [];

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (! in_array(strtolower((string) $name), $denied, true) && ! str_starts_with(strtolower((string) $name), 'idempotent-')) {
                $headers[(string) $name] = array_values($values);
            }
        }

        return $headers;
    }

    /** Quién actúa: la clave es de este actor y no de otro. */
    private function actor(Request $request): string
    {
        if ($request->attributes->get('authenticated_via') === 'api-key' && $request->attributes->get('api_key_id')) {
            return 'api_key:'.$request->attributes->get('api_key_id');
        }

        $user = $request->user();

        return $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
    }

    /** Hash del cuerpo canónico: mismas claves en otro orden dan el mismo hash. */
    private function fingerprint(Request $request): string
    {
        $files = [];
        foreach ($request->allFiles() as $field => $file) {
            $files[$field] = $this->fileFingerprint($file);
        }

        $payload = [
            'query' => $request->query->all(),
            'body' => $request->isJson() ? $request->json()->all() : $request->request->all(),
            'files' => $files,
        ];

        return hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fileFingerprint(mixed $file): mixed
    {
        if (is_array($file)) {
            return array_map(fn ($f) => $this->fileFingerprint($f), $file);
        }

        return $file instanceof \SplFileInfo && $file->isFile()
            ? [$file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename(), $file->getSize(), hash_file('sha256', $file->getPathname())]
            : null;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => $this->canonical($v), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
