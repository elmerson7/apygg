<?php

namespace App\Models;

use App\Exceptions\PrivacyException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Documento legal versionado (docs/privacy.md). Inmutable: una corrección es una versión nueva.
 *
 * @property int $id
 * @property string $key
 * @property string $version
 * @property string $locale
 * @property string $title
 * @property string $body
 * @property string $content_hash
 * @property CarbonInterface $effective_at
 * @property int|null $created_by
 */
class LegalDocument extends EloquentModel
{
    protected $guarded = [];

    protected $casts = ['effective_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw PrivacyException::documentImmutable());
        static::deleting(fn () => throw PrivacyException::documentImmutable());
    }
}
