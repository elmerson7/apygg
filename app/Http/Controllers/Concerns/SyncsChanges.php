<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\OptimisticLockInterface;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pull incremental para apps offline (módulo `offline-sync`, docs/offline-sync.md):
 * `GET <recurso>?updated_since=<ISO8601>&cursor=...&per_page=...`.
 *
 * - Incluye los borrados (soft deletes) como `{id, deleted_at}`.
 * - Orden estable (updated_at, id) y paginación por cursor (`sendCursorPaginated`).
 * - `meta.server_time`: tómalo de la ÚLTIMA página (`has_more=false`) y envíalo como `updated_since` en la
 *   siguiente sincronización. Es "ahora" menos un margen (`offline-sync.clock_skew_seconds`): la app puede recibir
 *   alguna fila otra vez y debe aplicarla de forma idempotente (upsert por id).
 * - Los hard deletes no se ven aquí: usa soft deletes en los modelos sincronizables.
 *
 * El controller valida con `App\Http\Requests\Sync\SyncChangesRequest` y autoriza antes de llamar.
 *
 * @phpstan-require-extends Controller
 */
trait SyncsChanges
{
    /**
     * @param  Builder<Model>  $query  ya acotado (el scope de tenant lo pone el modelo); se le añade orden y filtro
     * @param  Closure(Model): array<string, mixed>  $present  serializa un registro vivo o borrado
     */
    protected function syncChanges(Request $request, Builder $query, Closure $present): JsonResponse
    {
        $serverTime = now()->subSeconds(max(0, (int) config('offline-sync.clock_skew_seconds', 5)));

        if ($request->query('updated_since') !== null && $request->query('updated_since') !== '') {
            $since = Carbon::parse((string) $request->query('updated_since'))->setTimezone((string) config('app.timezone'));
            $query->where($query->getModel()->getTable().'.updated_at', '>=', $since);
        }

        $max = max(1, (int) config('offline-sync.max_page_size', 500));
        $table = $query->getModel()->getTable();

        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $paginator = $query
            ->orderBy("{$table}.updated_at")
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->cursorPaginate($this->perPage($request, (int) config('offline-sync.page_size', 100), $max))
            ->through(fn (Model $m) => $present($m));

        return $this->sendCursorPaginated($paginator, [
            'server_time' => $serverTime->utc()->toIso8601ZuluString('microsecond'),
        ], false);
    }

    /** Valor de `If-Match` de la petición (null si no viene). */
    protected function ifMatchHeader(Request $request): ?string
    {
        $value = $request->header('If-Match');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Añade la cabecera `ETag` del registro a una respuesta. */
    protected function withEtag(JsonResponse $response, Model $model): JsonResponse
    {
        return $response->header('ETag', app(OptimisticLockInterface::class)->etag($model));
    }
}
