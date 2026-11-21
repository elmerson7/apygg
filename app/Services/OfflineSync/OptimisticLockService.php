<?php

namespace App\Services\OfflineSync;

use App\Contracts\OptimisticLockInterface;
use App\Exceptions\ApiException;
use App\Exceptions\OfflineSync\OfflineSyncException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Concurrencia optimista con ETag / If-Match (docs/offline-sync.md). Sin estado entre peticiones (Octane).
 *
 * La comprobación y la escritura ocurren con la fila bloqueada: dos PATCH con el mismo `If-Match` no pasan los dos.
 */
class OptimisticLockService implements OptimisticLockInterface
{
    public function etag(Model $model): string
    {
        $attributes = $model->getAttributes();
        ksort($attributes);

        return '"'.substr(hash('sha256', (string) json_encode($attributes, JSON_PARTIAL_OUTPUT_ON_ERROR)), 0, 32).'"';
    }

    public function guard(Model $model, ?string $ifMatch, bool $required, Closure $apply, bool $withTrashed = false): mixed
    {
        return DB::transaction(function () use ($model, $ifMatch, $required, $apply, $withTrashed) {
            $query = $model->newQuery();

            if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            // El scope de tenant sigue aplicándose: una fila de otro tenant no se encuentra.
            $fresh = $query->whereKey($model->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw ApiException::notFound();
            }

            $given = $this->parse($ifMatch);

            if ($given === []) {
                if ($required) {
                    throw OfflineSyncException::preconditionRequired();
                }
            } elseif (! in_array('*', $given, true) && ! in_array(trim($this->etag($fresh), '"'), $given, true)) {
                throw OfflineSyncException::preconditionFailed($this->etag($fresh));
            }

            return $apply($fresh);
        });
    }

    /**
     * Valores de un `If-Match` ("a", W/"b", *), sin comillas ni `W/`.
     *
     * @return list<string>
     */
    private function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $v) => trim(preg_replace('/^W\//', '', trim($v)) ?? '', '" '),
            explode(',', $header)
        ), fn (string $v) => $v !== ''));
    }
}
