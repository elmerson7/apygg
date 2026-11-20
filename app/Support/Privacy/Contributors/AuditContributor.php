<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría PROPIA: lo que hizo el usuario como actor (sin los diffs, que pueden mencionar a terceros).
 * `erase()` no borra la auditoría (obligación legal): anonimiza al actor y quita la IP.
 */
class AuditContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'audit';
    }

    public function export(User $user): array
    {
        $max = (int) config('privacy.export.max_audit_rows', 10000);

        return ['actions' => DB::table('audit_logs')
            ->where('actor_type', 'user')->where('actor_id', (string) $user->getKey())
            ->orderBy('id')->limit($max)
            ->get(['tenant_id', 'event', 'auditable_type', 'auditable_id', 'reason', 'ip_address', 'created_at'])->all()];
    }

    public function erase(User $user): void
    {
        // Consulta directa: `AuditLog` es inmutable para la aplicación; esto es retención legal, como `audit:prune`.
        DB::table('audit_logs')->where('actor_type', 'user')->where('actor_id', (string) $user->getKey())
            ->update(['actor_id' => null, 'ip_address' => null]);
    }
}
