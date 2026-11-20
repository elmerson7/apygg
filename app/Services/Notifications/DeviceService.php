<?php

namespace App\Services\Notifications;

use App\Contracts\DeviceServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Collection;

class DeviceService implements DeviceServiceInterface
{
    public function register(User $user, array $data): UserDevice
    {
        $existing = UserDevice::query()->where('token', $data['token'])->first();

        // Un dispositivo nuevo para este usuario respeta el máximo; refrescar o mover uno existente, no.
        if (($existing === null || $existing->user_id !== $user->getKey()) && $this->count($user) >= (int) config('notifications.devices.max_per_user', 20)) {
            throw new BusinessLogicException('Has alcanzado el máximo de dispositivos registrados', 'device_limit_reached');
        }

        // Si la app reinstalada vuelve con el mismo `device_id` y otro token, sustituye al antiguo.
        if (! empty($data['device_id'])) {
            UserDevice::query()
                ->where('user_id', $user->getKey())
                ->where('device_id', $data['device_id'])
                ->where('token', '!=', $data['token'])
                ->delete();
        }

        // Atómico: el UNIQUE(token) decide, así dos registros simultáneos del mismo token no chocan.
        UserDevice::query()->upsert([[
            'user_id' => $user->getKey(),
            'token' => $data['token'],
            'platform' => $data['platform'],
            'device_id' => $data['device_id'] ?? null,
            'name' => $data['name'] ?? null,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['token'], ['user_id', 'platform', 'device_id', 'name', 'last_seen_at', 'updated_at']);

        return UserDevice::query()->where('token', $data['token'])->firstOrFail();
    }

    public function list(User $user): Collection
    {
        return UserDevice::query()->where('user_id', $user->getKey())->orderByDesc('last_seen_at')->orderByDesc('id')->get();
    }

    public function remove(User $user, int $id): void
    {
        UserDevice::query()->where('user_id', $user->getKey())->findOrFail($id)->delete();
    }

    public function forgetToken(string $token): void
    {
        UserDevice::query()->where('token', $token)->delete();
    }

    public function purgeStale(int $days): int
    {
        return UserDevice::query()->where(fn ($q) => $q->where('last_seen_at', '<', now()->subDays($days))
            ->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('created_at', '<', now()->subDays($days))))->delete();
    }

    private function count(User $user): int
    {
        return UserDevice::query()->where('user_id', $user->getKey())->count();
    }
}
