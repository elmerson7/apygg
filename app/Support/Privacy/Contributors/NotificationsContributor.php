<?php

namespace App\Support\Privacy\Contributors;

use App\Contracts\Privacy\PersonalDataContributor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja (de todos sus tenants), preferencias y dispositivos push. Nunca el token del dispositivo.
 */
class NotificationsContributor implements PersonalDataContributor
{
    public function key(): string
    {
        return 'notifications';
    }

    public function export(User $user): array
    {
        $id = $user->getKey();

        return [
            'inbox' => DB::table('inbox_notifications')->where('user_id', $id)->whereNull('deleted_at')->orderBy('id')
                ->get(['tenant_id', 'type', 'title', 'body', 'read_at', 'created_at'])->all(),
            'preferences' => DB::table('notification_preferences')->where('user_id', $id)->orderBy('id')
                ->get(['type', 'channel', 'enabled'])->all(),
            'devices' => DB::table('user_devices')->where('user_id', $id)->orderBy('id')
                ->get(['platform', 'device_id', 'name', 'last_seen_at', 'created_at'])->all(),
        ];
    }

    public function erase(User $user): void
    {
        $id = $user->getKey();

        DB::table('inbox_notifications')->where('user_id', $id)->delete();
        DB::table('notification_preferences')->where('user_id', $id)->delete();
        DB::table('user_devices')->where('user_id', $id)->delete();
        DB::table('notification_deliveries')->where('user_id', $id)->delete();
    }
}
