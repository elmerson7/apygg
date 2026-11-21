<?php

namespace App\Contracts;

use App\Models\User;
use App\Support\OfflineSync\SyncResource;
use Illuminate\Database\Eloquent\Model;

interface OfflineSyncInterface
{
    /** Definición del recurso sincronizable (404 `sync_resource_unknown` si no está en config). */
    public function definition(string $resource): SyncResource;

    /**
     * Comprueba permiso declarado Y policy del modelo (si existe); sin ninguno de los dos, deniega.
     *
     * @param  'viewAny'|'create'|'update'|'delete'  $action
     */
    public function authorize(User $user, string $action, SyncResource $definition, ?Model $model = null): void;

    /**
     * Cómo se envía un registro al cliente: borrado -> `{id, deleted_at}`; si no, su Resource (o `toArray()`).
     *
     * @return array<string, mixed>
     */
    public function present(Model $model, SyncResource $definition): array;

    /**
     * Procesa un lote. Cada operación es independiente (su transacción, su idempotencia, sus permisos): una que
     * falla no afecta a las demás. Devuelve un resultado por operación, en el mismo orden.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function batch(User $user, array $operations): array;
}
