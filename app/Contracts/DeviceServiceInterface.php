<?php

namespace App\Contracts;

use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Collection;

/**
 * DeviceServiceInterface
 *
 * Dispositivos push del usuario. Un token solo puede estar activo en UN usuario: registrarlo lo mueve
 * (el último que inicia sesión en el dispositivo se queda con él).
 */
interface DeviceServiceInterface
{
    /**
     * Registra o refresca (`last_seen_at`) un dispositivo. Idempotente por `token`.
     *
     * @param  array{token: string, platform: string, device_id?: string|null, name?: string|null}  $data
     *
     * @throws BusinessLogicException al superar el máximo de dispositivos por usuario
     */
    public function register(User $user, array $data): UserDevice;

    /**
     * @return Collection<int, UserDevice>
     */
    public function list(User $user): Collection;

    public function remove(User $user, int $id): void;

    /** Borra por token (el proveedor dijo que ya no existe). */
    public function forgetToken(string $token): void;

    /** @return int dispositivos sin verse en `$days` días que se borraron */
    public function purgeStale(int $days): int;
}
