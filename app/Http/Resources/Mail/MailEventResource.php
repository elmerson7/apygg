<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use App\Models\Mail\MailEvent;
use Illuminate\Http\Request;

/**
 * @mixin MailEvent
 */
class MailEventResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'type' => $this->type,
            'provider_message_id' => $this->provider_message_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
