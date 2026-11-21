<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos de la subida por partes (S3 multipart) de una `FileUploadIntent`. Se alcanza siempre a través de
 * la intención (aislada por tenant); no tiene tenant propio.
 *
 * @property int $id
 * @property int $file_upload_intent_id
 * @property string $upload_id
 * @property int $part_size
 * @property int $parts_count
 */
class FileMultipartUpload extends Model
{
    /** @var list<string> */
    protected $fillable = ['file_upload_intent_id', 'upload_id', 'part_size', 'parts_count'];

    /** @var array<string, string> */
    protected $casts = [
        'part_size' => 'integer',
        'parts_count' => 'integer',
    ];

    /**
     * @return BelongsTo<FileUploadIntent, $this>
     */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(FileUploadIntent::class, 'file_upload_intent_id');
    }
}
