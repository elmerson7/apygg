<?php

namespace App\Http\Controllers\Sync;

use App\Contracts\OfflineSyncInterface;
use App\Http\Controllers\Concerns\SyncsChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncBatchRequest;
use App\Http\Requests\Sync\SyncChangesRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Módulo `offline-sync` (docs/offline-sync.md): pull incremental genérico y lotes de operaciones.
 */
class SyncController extends Controller
{
    use SyncsChanges;

    public function __construct(private readonly OfflineSyncInterface $sync) {}

    /** GET /sync/{resource}?updated_since=... — cambios (y borrados) desde una marca. */
    public function changes(SyncChangesRequest $request, string $resource): JsonResponse
    {
        $definition = $this->sync->definition($resource);
        $this->sync->authorize($request->user(), 'viewAny', $definition);

        return $this->syncChanges(
            $request,
            $definition->model::query(),
            fn (Model $model) => $this->sync->present($model, $definition),
        );
    }

    /** POST /sync/batch — lote de operaciones; HTTP 200 con un resultado por operación. */
    public function batch(SyncBatchRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $operations */
        $operations = $request->validated('operations');
        $results = $this->sync->batch($request->user(), $operations);

        $failed = count(array_filter($results, fn (array $r) => ! $r['ok']));

        return $this->sendSuccess([
            'results' => $results,
            'total' => count($results),
            'succeeded' => count($results) - $failed,
            'failed' => $failed,
        ], 'Lote procesado');
    }
}
