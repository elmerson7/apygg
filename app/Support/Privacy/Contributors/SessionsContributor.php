<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Contracts\SessionServiceInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sesiones por dispositivo. Nunca el `refresh_jti` (ni el anterior). `erase()` las revoca (invalida la caché de
 * `isActive`) y borra las filas.
 */
class SessionsContributor implements PersonalDataContributor
{
    public function __construct(private readonly SessionServiceInterface $sessions) {}

    public function key(): string
    {
        return 'sessions';
    }

    public function export(User $user): array
    {
        return ['sessions' => DB::table('user_sessions')->where('user_id', $user->getKey())->orderBy('id')
            ->get(['public_id', 'device', 'user_agent', 'ip', 'created_at', 'last_used_at', 'revoked_at', 'revoked_reason'])->all()];
    }

    public function erase(User $user): void
    {
        $this->sessions->revokeOthers($user, null, 'account-deleted');
        DB::table('user_sessions')->where('user_id', $user->getKey())->delete();
    }
}
