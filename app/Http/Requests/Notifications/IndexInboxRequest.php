<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\BaseFormRequest;

/**
 * Listado de la bandeja: `?unread=1`, `?type=`, `?per_page=`.
 */
class IndexInboxRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('notifications.inbox.max_per_page', 100)],
        ];
    }
}
