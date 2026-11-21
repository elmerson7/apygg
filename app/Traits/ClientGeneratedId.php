<?php

namespace App\Traits;

use App\Exceptions\OfflineSync\OfflineSyncException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ID de clave primaria generado por el cliente (módulo `offline-sync`, docs/offline-sync.md). Por modelo:
 * los IDs por defecto del boilerplate siguen siendo enteros.
 *
 * Sustituye a `HasUuid` (no se usan juntos): clave `string` no incremental y, si no viene, UUID v7 generado en el servidor.
 * `createFromClient()` acepta el ID del cliente y, si ya existe (en este tenant o en otro), responde SIEMPRE lo mismo
 * (409 `client_id_conflict`): no revela que el ID existe en otro tenant.
 *
 * @mixin Model
 */
trait ClientGeneratedId
{
    public static function bootClientGeneratedId(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid7());
            }
        });
    }

    public function initializeClientGeneratedId(): void
    {
        $this->setIncrementing(false);
        $this->setKeyType('string');
    }

    /** ¿Es un UUID RFC 4122/9562 de una versión aceptada por `offline-sync.client_id_versions`? */
    public static function isValidClientId(string $id): bool
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-([1-8])[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id, $m)) {
            return false;
        }

        $allowed = (array) config('offline-sync.client_id_versions', [4, 7]);

        return in_array((int) $m[1], array_map('intval', $allowed), true);
    }

    /**
     * Crea el modelo con el ID del cliente (o uno generado si es null).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OfflineSyncException `invalid_client_id` (422) o `client_id_conflict` (409)
     */
    public static function createFromClient(array $attributes, ?string $clientId = null): static
    {
        $model = new static;
        $model->fill($attributes);

        if ($clientId !== null) {
            if (! static::isValidClientId($clientId)) {
                throw OfflineSyncException::invalidClientId();
            }

            $model->setAttribute($model->getKeyName(), strtolower($clientId));
        }

        try {
            // Transacción anidada = savepoint: el fallo único no deja abortada la transacción exterior (PostgreSQL).
            DB::transaction(fn () => $model->save());
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), $model->getTable().'_pkey')) {
                throw OfflineSyncException::clientIdConflict();
            }

            throw $e;
        }

        return $model;
    }
}
