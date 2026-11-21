<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de un solo uso para entrar sin contraseña (módulo `otp-login`). Global como el usuario (sin `BelongsToTenant`):
 * se acota por `user_id` + `destination_hash`. El código nunca se guarda en claro.
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property string $destination_hash
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property string|null $ip
 */
class LoginOtp extends EloquentModel
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'channel', 'destination_hash', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
