<?php

namespace App\Models;

use App\Exceptions\PrivacyException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Entrada del registro de consentimientos (solo se añade; docs/privacy.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $document_key
 * @property string $version
 * @property string|null $locale
 * @property string $action
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonInterface $created_at
 */
class UserConsent extends EloquentModel
{
    public const UPDATED_AT = null;

    public const ACCEPTED = 'accepted';

    public const WITHDRAWN = 'withdrawn';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw PrivacyException::documentImmutable());
        static::deleting(fn () => throw PrivacyException::documentImmutable());
    }
}
