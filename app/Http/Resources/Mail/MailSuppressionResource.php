<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use App\Models\Mail\MailSuppression;
use Illuminate\Http\Request;

/**
 * @mixin MailSuppression
 */
class MailSuppressionResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'reason' => $this->reason,
            'source' => $this->source,
            'details' => $this->details,
            'suppressed_at' => $this->suppressed_at->toIso8601String(),
        ];
    }
}
