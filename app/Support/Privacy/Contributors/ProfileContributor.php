<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Perfil y cuenta. `erase()` anonimiza la cuenta (se conserva la fila para no romper claves foráneas y rastros
 * de auditoría) y la da de baja. Debe ser el ÚLTIMO contribuidor en ejecutarse.
 */
class ProfileContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'profile';
    }

    public function export(User $user): array
    {
        $profile = DB::table('user_profiles')->where('user_id', $user->getKey())->first(
            ['first_name', 'last_name', 'phone', 'bio', 'date_of_birth', 'gender', 'address', 'city', 'state', 'country', 'postal_code', 'social_links'],
        );

        return [
            'account' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'identity_document' => $user->identity_document,
                'preferences' => $user->preferences,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
            ],
            'profile' => $profile,
        ];
    }

    public function erase(User $user): void
    {
        $id = $user->getKey();

        DB::table('user_profiles')->where('user_id', $id)->delete();

        // Consulta directa: sin eventos del modelo (no hay que auditar un diff con datos que se acaban de borrar).
        DB::table('users')->where('id', $id)->update([
            'name' => 'Usuario eliminado',
            'email' => "deleted-{$id}@deleted.invalid",
            'username' => null,
            'identity_document' => null,
            'preferences' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'email_verified_at' => null,
            'token_version' => DB::raw('token_version + 1'),
            'deleted_at' => DB::raw('COALESCE(deleted_at, NOW())'),
            'updated_at' => now(),
        ]);
    }
}
