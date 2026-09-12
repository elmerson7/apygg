<?php

namespace App\Models\Docs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DocChunk Model
 *
 * Fragmento indexado de la documentación del proyecto (`docs/*.md`), partido
 * por encabezados.
 *
 * Se indexa por fragmentos y no por documento entero porque un `.md` de 400
 * líneas no cabe cómodamente en un prompt, y porque la respuesta es mejor
 * cuando se le dan al modelo tres secciones relevantes en lugar de seis
 * documentos completos.
 *
 * La búsqueda usa el full-text nativo de PostgreSQL (`search_vector`), sin
 * extensiones. Para búsqueda semántica, ver docs/docs-assistant.md.
 *
 * @property int $id
 * @property string $doc_id
 * @property string $scope
 * @property string|null $title
 * @property string|null $heading
 * @property string $content
 * @property string|null $content_hash
 * @property array<string, mixed>|null $metadata
 */
class DocChunk extends Model
{
    protected $table = 'doc_chunks';

    protected $fillable = [
        'doc_id',
        'scope',
        'title',
        'heading',
        'content',
        'content_hash',
        'position',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'position' => 'integer',
    ];

    public function scopeForScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    public function scopeForDoc(Builder $query, string $docId): Builder
    {
        return $query->where('doc_id', $docId);
    }

    /**
     * Referencia legible del fragmento, para citar la fuente en la respuesta.
     */
    public function reference(): string
    {
        return $this->heading
            ? "{$this->doc_id} › {$this->heading}"
            : $this->doc_id;
    }
}
