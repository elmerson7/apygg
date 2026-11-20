<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsentsContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'consents';
    }

    public function export(User $user): array
    {
        $rows = DB::table('user_consents')->where('user_id', $user->getKey())->orderBy('id')
            ->get(['document_key', 'version', 'locale', 'action', 'ip_address', 'user_agent', 'created_at']);

        return ['consents' => $rows->all()];
    }

    /**
     * La prueba del consentimiento (qué, cuándo, versión) se conserva; los datos que identifican (IP, navegador) no.
     */
    public function erase(User $user): void
    {
        DB::table('user_consents')->where('user_id', $user->getKey())->update(['ip_address' => null, 'user_agent' => null]);
    }
}
