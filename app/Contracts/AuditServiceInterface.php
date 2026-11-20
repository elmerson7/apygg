<?php

namespace App\Contracts;

use App\Exceptions\AuditException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface AuditServiceInterface
{
    /**
     * Registra un cambio de modelo (lo llama el trait `Auditable`). `$old`/`$new` ya vienen filtrados
     * de atributos ocultos/excluidos; aquí se redactan los secretos y datos personales. Escribe en la
     * misma transacción que el cambio: si esta se revierte, la entrada también.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function recordModelEvent(Model $model, string $event, ?array $old, ?array $new): ?AuditLog;

    /**
     * Registra una acción sensible de administración (bloquear usuario, cambiar rol...). Con `$reason`
     * vacío falla con `audit_reason_required` si la acción está en `audit.reason_required`.
     *
     * @param  array<string, mixed>  $context  datos extra (se redactan) que se guardan en `new_values`
     */
    public function recordAction(string $action, ?Model $subject = null, ?string $reason = null, array $context = [], ?int $tenantId = null): ?AuditLog;

    /**
     * Valida y normaliza el motivo de una acción ANTES de ejecutarla (para fallar sin efectos). Devuelve el
     * motivo recortado o null si no se dio y la acción no lo exige.
     *
     * @throws AuditException
     */
    public function requireReason(string $action, ?string $reason): ?string;

    /**
     * Auditoría de UN tenant. Exige que el rol del actor EN ese tenant conceda `tenant-audit.read`
     * (con equipos de Spatie apagados el `permission:` de la ruta no basta).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginateForTenant(Tenant $tenant, User $actor, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Auditoría de toda la plataforma (`tenant_id` opcional en los filtros).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginateForPlatform(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * Filas CSV (la primera es la cabecera) de la auditoría del tenant, leídas por lotes. La comprobación
     * de permiso es inmediata (antes de empezar a emitir); las filas se generan al iterar.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, list<string>>
     */
    public function exportForTenant(Tenant $tenant, User $actor, array $filters): \Generator;

    /**
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, list<string>>
     */
    public function exportForPlatform(array $filters): \Generator;

    /**
     * Borra la auditoría anterior a `$days` días (o a `audit.retention_days`). Devuelve cuántas borró
     * (con `$dryRun`, cuántas borraría). 0 días = retención infinita: no borra nada.
     */
    public function prune(?int $days = null, bool $dryRun = false): int;
}
