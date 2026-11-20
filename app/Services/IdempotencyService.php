<?php

namespace App\Services;

use App\Contracts\IdempotencyInterface;
use App\Support\Idempotency\Reservation;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claves de idempotencia HTTP (docs/idempotency.md).
 *
 * La reserva es atómica: `INSERT ... ON CONFLICT DO NOTHING` contra el índice único (tenant, actor, clave).
 * Quien inserta ejecuta; los demás leen la fila. Una fila caducada se toma con un `UPDATE ... WHERE expires_at <= now`
 * (solo uno gana). `complete`/`release` exigen el `token` de la reserva: un proceso que perdió la reserva por
 * caducidad no pisa a quien la tomó después. Sin estado entre peticiones (Octane).
 */
class IdempotencyService implements IdempotencyInterface
{
    private const POLL_MICROSECONDS = 50_000;

    public function begin(?int $tenantId, string $actor, string $key, string $method, string $path, string $requestHash): Reservation
    {
        $deadline = microtime(true) + max(0, (int) config('idempotency.wait_ms', 0)) / 1000;

        while (true) {
            $reservation = $this->attempt($tenantId, $actor, $key, $method, $path, $requestHash);

            if ($reservation->outcome !== Reservation::IN_PROGRESS || microtime(true) >= $deadline) {
                return $reservation;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    public function complete(Reservation $reservation, int $status, array $headers, string $body): bool
    {
        $ttl = max(1, (int) config('idempotency.ttl_hours', 24));

        return DB::table('idempotency_keys')
            ->where('id', $reservation->id)
            ->where('token', $reservation->token)
            ->where('state', 'processing')
            ->update([
                'state' => 'completed',
                'response_status' => $status,
                'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                'response_body' => Crypt::encryptString($body),
                'expires_at' => now()->addHours($ttl),
                'updated_at' => now(),
            ]) === 1;
    }

    public function release(Reservation $reservation): void
    {
        DB::table('idempotency_keys')
            ->where('id', $reservation->id)
            ->where('token', $reservation->token)
            ->where('state', 'processing')
            ->delete();
    }

    public function purgeExpired(int $batch = 1000): int
    {
        $total = 0;

        do {
            $ids = DB::table('idempotency_keys')->where('expires_at', '<=', now())->limit($batch)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table('idempotency_keys')->whereIn('id', $ids)->where('expires_at', '<=', now())->delete();
        } while ($ids->count() === $batch);

        return $total;
    }

    private function attempt(?int $tenantId, string $actor, string $key, string $method, string $path, string $hash): Reservation
    {
        // Dos vueltas bastan en la práctica; el bucle cubre filas que desaparecen entre el INSERT y el SELECT.
        for ($i = 0; $i < 5; $i++) {
            $token = (string) Str::uuid();
            $lockUntil = now()->addSeconds(max(1, (int) config('idempotency.lock_seconds', 120)));

            $inserted = DB::selectOne(
                'INSERT INTO idempotency_keys (tenant_id, actor, key, token, method, path, request_hash, state, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'processing\', ?, ?, ?)
                 ON CONFLICT DO NOTHING RETURNING id',
                [$tenantId, $actor, $key, $token, $method, $path, $hash, $lockUntil, now(), now()]
            );

            if ($inserted !== null) {
                return new Reservation(Reservation::ACQUIRED, (int) $inserted->id, $token);
            }

            $row = DB::table('idempotency_keys')
                ->whereRaw('COALESCE(tenant_id, 0) = ?', [$tenantId ?? 0])
                ->where('actor', $actor)
                ->where('key', $key)
                ->first();

            if ($row === null) {
                continue;
            }

            if (Carbon::parse($row->expires_at)->lte(now())) {
                $taken = DB::table('idempotency_keys')
                    ->where('id', $row->id)
                    ->where('expires_at', '<=', now())
                    ->update([
                        'token' => $token, 'method' => $method, 'path' => $path, 'request_hash' => $hash,
                        'state' => 'processing', 'response_status' => null, 'response_headers' => null,
                        'response_body' => null, 'expires_at' => $lockUntil, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                if ($taken === 1) {
                    return new Reservation(Reservation::ACQUIRED, (int) $row->id, $token);
                }

                continue;
            }

            if ($row->method !== $method || $row->path !== $path || $row->request_hash !== $hash) {
                return new Reservation(Reservation::MISMATCH);
            }

            if ($row->state !== 'completed') {
                return new Reservation(Reservation::IN_PROGRESS);
            }

            try {
                $body = Crypt::decryptString((string) $row->response_body);
            } catch (DecryptException) {
                // APP_KEY rotada: no se puede reproducir ni es seguro volver a ejecutar; se trata como en curso.
                return new Reservation(Reservation::IN_PROGRESS);
            }

            /** @var array<string, list<string|null>> $headers */
            $headers = json_decode((string) $row->response_headers, true) ?: [];

            return new Reservation(Reservation::REPLAY, (int) $row->id, null, (int) $row->response_status, $headers, $body);
        }

        return new Reservation(Reservation::IN_PROGRESS);
    }
}
