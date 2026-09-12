<?php

namespace App\Http\Resources\Mail;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

class MailProviderResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),
            'name' => $this->name,
            'driver' => $this->driver,
            'config' => $this->config,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'accounts_count' => $this->whenCounted('accounts'),
        ];
    }
}
