<?php

namespace App\Http\Resources\Notifications;

use App\Http\Resources\BaseResource;
use App\Models\InboxNotification;
use Illuminate\Http\Request;

/**
 * @mixin InboxNotification
 */
class InboxNotificationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InboxNotification $notification */
        $notification = $this->resource;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data,
            'read' => $notification->isRead(),
            'read_at' => $this->formatDate($notification->read_at),
            'created_at' => $this->formatDate($notification->created_at),
        ];
    }
}
