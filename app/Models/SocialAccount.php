<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identidad externa vinculada a un usuario (módulo `social-login`). Global como el usuario: sin `BelongsToTenant`;
 * se acota siempre por `user_id`/`provider_user_id`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property bool $email_verified
 * @property CarbonInterface|null $last_login_at
 */
class SocialAccount extends EloquentModel
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'email_verified', 'last_login_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email_verified' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
