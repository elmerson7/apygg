<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Http\Request;

/**
 * @mixin MailTemplateConfig
 */
class MailTemplateConfigResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'template_key' => $this->template_key,
            'template_name' => $this->template_name,
            'is_active' => $this->is_active,
            'subject_template' => $this->subject_template,
            'description' => $this->description,
            'mail_account_id' => $this->mail_account_id,
            'mail_account' => $this->includeWhenLoaded(
                'mailAccount',
                new MailAccountResource($this->whenLoaded('mailAccount'))
            ),
        ];
    }
}
