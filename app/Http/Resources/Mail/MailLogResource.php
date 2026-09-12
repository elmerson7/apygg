<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

class MailLogResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'template_key' => $this->template_key,
            'to_email' => $this->to_email,
            'to_name' => $this->to_name,
            'recipient_type' => $this->recipient_type,
            'subject' => $this->subject,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'provider_message_id' => $this->provider_message_id,
            'metadata' => $this->metadata,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'mail_account' => $this->includeWhenLoaded(
                'mailAccount',
                new MailAccountResource($this->whenLoaded('mailAccount'))
            ),
            'events' => $this->includeWhenLoaded(
                'events',
                MailEventResource::collection($this->whenLoaded('events'))
            ),
        ];
    }
}
