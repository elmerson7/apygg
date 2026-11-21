<?php

namespace App\Services\OfflineSync;

use App\Contracts\IdempotencyInterface;
use App\Contracts\OfflineSyncInterface;
use App\Contracts\OptimisticLockInterface;
use App\Exceptions\ApiException;
use App\Exceptions\OfflineSync\OfflineSyncException;
use App\Models\User;
use App\Support\Idempotency\Reservation;
use App\Support\OfflineSync\SyncResource;
use App\Support\Tenancy\TenantContext;
use App\Traits\BelongsToTenant;
use App\Traits\ClientGeneratedId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Módulo `offline-sync` (docs/offline-sync.md): lotes de operaciones de una app sin conexión.
 *
 * - Cada operación va en SU transacción, con SU clave de idempotencia (tabla `idempotency_keys`, reserva atómica
 *   por (tenant, actor, clave)) y SUS permisos/policy. Un fallo no arrastra a las demás.
 * - Aislamiento: los modelos usan `BelongsToTenant` (fail-closed); un id de otro tenant es un 404 / 409 idéntico al
 *   de un id inexistente / ya usado.
 * - Resultados 4xx se guardan para repetirse; un 5xx libera la clave (el reintento se ejecuta).
 * Sin estado entre peticiones (Octane).
 */
class OfflineSyncService implements OfflineSyncInterface
{
    public function __construct(
        private readonly IdempotencyInterface $idempotency,
        private readonly OptimisticLockInterface $locks,
        private readonly TenantContext $tenant,
    ) {}

    public function definition(string $resource): SyncResource
    {
        /** @var array<string, mixed>|null $cfg */
        $cfg = config('offline-sync.resources.'.$resource);

        if (! is_array($cfg) || empty($cfg['model']) || ! is_a($cfg['model'], Model::class, true)) {
            throw OfflineSyncException::unknownResource();
        }

        $create = (array) ($cfg['create_rules'] ?? []);
        $update = isset($cfg['update_rules']) ? (array) $cfg['update_rules'] : $this->withSometimes($create);

        return new SyncResource(
            $resource,
            (string) $cfg['model'],
            $cfg['resource'] ?? null,
            (array) ($cfg['permissions'] ?? []),
            $create,
            $update,
            (bool) ($cfg['require_if_match'] ?? false),
        );
    }

    public function authorize(User $user, string $action, SyncResource $definition, ?Model $model = null): void
    {
        $permission = $definition->permissions[$action] ?? null;
        $hasPolicy = Gate::getPolicyFor($definition->model) !== null;

        if ($permission === null && ! $hasPolicy) {
            throw ApiException::forbidden(); // fail-closed: sin permiso ni policy declarados
        }

        if ($permission !== null) {
            foreach ((array) $permission as $p) {
                if (! $user->can($p)) {
                    throw ApiException::forbidden();
                }
            }
        }

        if ($hasPolicy) {
            /** @var GateContract $gate */
            $gate = Gate::forUser($user);

            try {
                $gate->authorize($action, $model ?? $definition->model);
            } catch (AuthorizationException) {
                throw ApiException::forbidden();
            }
        }
    }

    public function present(Model $model, SyncResource $definition): array
    {
        if (method_exists($model, 'trashed') && $model->trashed()) {
            return ['id' => $model->getKey(), 'deleted_at' => $model->getAttribute('deleted_at')?->toIso8601String()];
        }

        if ($definition->resource !== null) {
            /** @var array<string, mixed> $data */
            $data = (new ($definition->resource)($model))->resolve();

            return $data;
        }

        return $model->toArray();
    }

    public function batch(User $user, array $operations): array
    {
        $results = [];

        foreach ($operations as $operation) {
            $results[] = $this->run($user, $operation);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function run(User $user, array $op): array
    {
        $key = isset($op['idempotency_key']) ? (string) $op['idempotency_key'] : null;
        $reservation = null;

        if ($key !== null) {
            $reservation = $this->idempotency->begin(
                $this->tenant->id(),
                'user:'.$user->getKey(),
                'sync:'.$key,
                'SYNC',
                '/sync/batch/'.($op['resource'] ?? '').'/'.($op['op'] ?? '').'/'.($op['id'] ?? ''),
                $this->fingerprint($op),
            );

            if ($reservation->outcome === Reservation::REPLAY) {
                /** @var array<string, mixed> $stored */
                $stored = json_decode((string) $reservation->body, true) ?: [];

                return [...$stored, 'replayed' => true];
            }

            if ($reservation->outcome === Reservation::MISMATCH) {
                return $this->failure($op, 422, 'idempotency_key_mismatch', 'Esta clave de idempotencia ya se usó con otra operación.');
            }

            if ($reservation->outcome === Reservation::IN_PROGRESS) {
                return $this->failure($op, 409, 'idempotency_key_in_progress', 'Una operación con esta clave sigue en curso.');
            }
        }

        try {
            $result = $this->success($op, DB::transaction(fn () => $this->execute($user, $op)));
        } catch (ValidationException $e) {
            $result = $this->failure($op, 422, 'validation_failed', 'Error de validación', $e->errors());
        } catch (ApiException $e) {
            $result = $this->failure($op, $e->getStatusCode(), $this->codeOf($e), $e->getMessage(), $e->getErrors(), $e->getExtensions());
        } catch (ModelNotFoundException) {
            $result = $this->failure($op, 404, 'not_found', 'Recurso no encontrado');
        } catch (UniqueConstraintViolationException) {
            $result = $this->failure($op, 409, 'duplicate', 'Ya existe un registro con esos datos.');
        } catch (Throwable $e) {
            report($e);

            if ($reservation !== null) {
                $this->idempotency->release($reservation);
            }

            return $this->failure($op, 500, 'server_error', 'Error interno; reintenta con la misma clave.');
        }

        if ($reservation !== null) {
            $stored = json_encode($result, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $this->idempotency->complete($reservation, $result['status'], [], $stored);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array{int, Model, bool} [status, modelo, borrado]
     */
    private function execute(User $user, array $op): array
    {
        $def = $this->definition((string) ($op['resource'] ?? ''));
        $this->assertTenantSafe($def);

        $data = is_array($op['data'] ?? null) ? $op['data'] : [];
        $id = isset($op['id']) ? (string) $op['id'] : null;
        $ifMatch = isset($op['if_match']) ? (string) $op['if_match'] : null;

        return match ($op['op'] ?? null) {
            'create' => $this->create($user, $def, $data, $id),
            'update' => $this->update($user, $def, $data, $this->requireId($id), $ifMatch),
            'delete' => $this->delete($user, $def, $this->requireId($id), $ifMatch),
            default => throw ApiException::validation('Operación no válida.', ['op' => ['Debe ser create, update o delete.']]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{int, Model, bool}
     */
    private function create(User $user, SyncResource $def, array $data, ?string $id): array
    {
        $this->authorize($user, 'create', $def);

        $class = $def->model;
        $supportsClientId = in_array(ClientGeneratedId::class, class_uses_recursive($class), true);

        if ($id !== null && ! $supportsClientId) {
            throw OfflineSyncException::clientIdNotSupported();
        }

        $validated = Validator::make($data, $def->createRules)->validate();

        /** @var Model $model */
        $model = $supportsClientId
            ? $class::createFromClient($validated, $id) // @phpstan-ignore-line trait ClientGeneratedId
            : tap(new $class, function (Model $m) use ($validated) {
                $m->fill($validated);
                $m->save();
            });

        return [201, $model, false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{int, Model, bool}
     */
    private function update(User $user, SyncResource $def, array $data, string $id, ?string $ifMatch): array
    {
        $model = $this->find($def, $id, false);
        $this->authorize($user, 'update', $def, $model);

        $validated = Validator::make($data, $def->updateRules)->validate();

        $fresh = $this->locks->guard($model, $ifMatch, $def->requireIfMatch, function (Model $fresh) use ($validated) {
            $fresh->fill($validated)->save();

            return $fresh;
        });

        return [200, $fresh, false];
    }

    /**
     * @return array{int, Model, bool}
     */
    private function delete(User $user, SyncResource $def, string $id, ?string $ifMatch): array
    {
        $model = $this->find($def, $id, true);

        // Se autoriza antes de mirar si ya está borrado: sin permiso no se revela el registro ni su estado.
        $this->authorize($user, 'delete', $def, $model);

        if (method_exists($model, 'trashed') && $model->trashed()) {
            return [200, $model, true]; // ya borrado: idempotente
        }

        $fresh = $this->locks->guard($model, $ifMatch, $def->requireIfMatch, function (Model $fresh) {
            $fresh->delete();

            return $fresh;
        }, true);

        return [200, $fresh, true];
    }

    private function find(SyncResource $def, string $id, bool $withTrashed): Model
    {
        $class = $def->model;
        $uuidKey = in_array(ClientGeneratedId::class, class_uses_recursive($class), true);

        // Un id con forma inválida para la clave sería un error de PostgreSQL: es un 404, como cualquier id ausente.
        if (($uuidKey && ! ClientGeneratedId::isValidClientId($id)) || (! $uuidKey && ! ctype_digit($id))) {
            throw new ModelNotFoundException;
        }

        $query = $class::query();

        if ($withTrashed && $this->softDeletes($class)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var Model */
        return $query->findOrFail($id);
    }

    /**
     * @param  array{int, Model, bool}  $outcome
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function success(array $op, array $outcome): array
    {
        [$status, $model] = $outcome;
        $def = $this->definition((string) $op['resource']);
        $model->refresh(); // lo que ve la BD: el ETag se calcula siempre sobre la fila leída

        return [
            'idempotency_key' => $op['idempotency_key'] ?? null,
            'resource' => $op['resource'],
            'op' => $op['op'],
            'id' => $model->getKey(),
            'status' => $status,
            'ok' => true,
            'data' => $this->present($model, $def),
            'etag' => $this->locks->etag($model),
            'replayed' => false,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $extensions
     * @return array<string, mixed>
     */
    private function failure(array $op, int $status, string $code, string $message, array $errors = [], array $extensions = []): array
    {
        return [
            'idempotency_key' => $op['idempotency_key'] ?? null,
            'resource' => $op['resource'] ?? null,
            'op' => $op['op'] ?? null,
            'id' => $op['id'] ?? null,
            'status' => $status,
            'ok' => false,
            'data' => null,
            'etag' => null,
            'replayed' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'errors' => $errors ?: null,
                ...$extensions,
            ], fn ($v) => $v !== null),
        ];
    }

    private function codeOf(ApiException $e): string
    {
        $code = $e->errorCode();

        return is_string($code) ? $code : $code->value;
    }

    private function softDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }

    private function requireId(?string $id): string
    {
        if ($id === null || $id === '') {
            throw ApiException::validation('Falta el id.', ['id' => ['El id es obligatorio para update y delete.']]);
        }

        return $id;
    }

    /** Con multiempresa activa, un modelo sin `BelongsToTenant` no se sincroniza: mezclaría datos de tenants. */
    private function assertTenantSafe(SyncResource $def): void
    {
        if ($this->tenant->enabled() && ! in_array(BelongsToTenant::class, class_uses_recursive($def->model), true)) {
            throw OfflineSyncException::misconfigured("El recurso {$def->name} debe usar BelongsToTenant con multiempresa activa.");
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function withSometimes(array $rules): array
    {
        return array_map(
            fn ($rule) => is_string($rule) ? 'sometimes|'.$rule : ['sometimes', ...(array) $rule],
            $rules
        );
    }

    /**
     * Huella canónica de la operación (claves de objetos ordenadas): misma clave + otra operación = mismatch.
     *
     * @param  array<string, mixed>  $op
     */
    private function fingerprint(array $op): string
    {
        $canon = function (mixed $v) use (&$canon) {
            if (is_array($v)) {
                array_is_list($v) ? null : ksort($v);

                return array_map($canon, $v);
            }

            return $v;
        };

        $payload = $canon([
            'resource' => $op['resource'] ?? null, 'op' => $op['op'] ?? null, 'id' => $op['id'] ?? null,
            'data' => $op['data'] ?? null, 'if_match' => $op['if_match'] ?? null,
        ]);

        return hash('sha256', (string) json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
