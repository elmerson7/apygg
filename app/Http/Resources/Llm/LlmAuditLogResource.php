<?php

namespace App\Http\Resources\Llm;

use App\Http\Resources\BaseResource;
use App\Models\Logs\LlmAuditLog;
use Illuminate\Http\Request;

/**
 * @mixin LlmAuditLog
 */
class LlmAuditLogResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'use_case' => $this->use_case,
            'module' => $this->module,
            'model' => $this->model,
            'status' => $this->status,
            'error' => $this->error,
            'prompt_tokens' => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
            'total_tokens' => $this->total_tokens,
            'meta' => $this->meta,
            'trace_id' => $this->trace_id,
            'prompt' => $this->prompt,
            'response' => $this->response,
            'actor' => $this->includeWhenLoaded('actor', [
                'id' => $this->actor?->id,
                'name' => $this->actor?->name,
                'email' => $this->actor?->email,
            ]),
        ];
    }
}
