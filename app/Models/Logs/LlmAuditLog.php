<?php

namespace App\Models\Logs;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LlmAuditLog Model
 *
 * Registro de cada interacción con un LLM: caso de uso, módulo, modelo,
 * tokens, estado, quién la disparó y con qué trace_id.
 *
 * Sin esto no se puede saber cuánto cuesta la IA, qué módulo la consume ni por
 * qué dejó de responder. En una funcionalidad que se factura por token, la
 * trazabilidad no es opcional.
 *
 * Usa ID auto-incrementable, como el resto de tablas de log.
 */
class LlmAuditLog extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $table = 'logs_llm';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'use_case',
        'module',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'status',
        'error',
        'prompt',
        'response',
        'meta',
        'actor_id',
        'trace_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_ERROR);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUseCase($query, string $useCase)
    {
        return $query->where('use_case', $useCase);
    }
}
