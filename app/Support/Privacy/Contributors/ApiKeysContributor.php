<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * API keys: nombre, scopes y fechas. Nunca la clave ni su hash. `erase()` las borra (revoca).
 */
class ApiKeysContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'api_keys';
    }

    public function export(User $user): array
    {
        return ['api_keys' => DB::table('api_keys')->where('user_id', $user->getKey())->orderBy('id')
            ->get(['tenant_id', 'name', 'scopes', 'last_used_at', 'expires_at', 'created_at'])->all()];
    }

    public function erase(User $user): void
    {
        DB::table('api_keys')->where('user_id', $user->getKey())->delete();
    }
}
