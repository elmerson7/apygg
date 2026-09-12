<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

class MailAccountResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'name' => $this->name,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'priority' => $this->priority,
            'mail_provider_id' => $this->mail_provider_id,
            'provider' => $this->includeWhenLoaded(
                'mailProvider',
                new MailProviderResource($this->whenLoaded('mailProvider'))
            ),
            'templates_count' => $this->whenCounted('templateConfigs'),
        ];
    }
}
