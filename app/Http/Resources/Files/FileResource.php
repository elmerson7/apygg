<?php

namespace App\Http\Resources\Files;

use App\Http\Resources\BaseResource;

class FileResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'formatted_size' => $this->getFormattedSize(),
            'checksum' => $this->checksum,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'type' => $this->getType(),
            'is_image' => $this->isImage(),
            'is_document' => $this->isDocument(),
            'meta' => $this->meta,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'download_url' => $this->when(
                $this->status === 'verified' && $this->visibility === 'public',
                fn() => $this->getUrl()
            ),
        ];
    }
}
