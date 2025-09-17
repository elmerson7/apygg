<?php

namespace App\Http\Resources\Files;

use App\Http\Resources\BaseResource;

class UploadUrlResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'file_id' => $this->resource['file_id'],
            'upload_url' => $this->resource['upload_url'],
            'expires_at' => $this->resource['expires_at'],
            'method' => $this->resource['meta']['method'],
            'headers' => $this->resource['meta']['headers'],
            'instructions' => [
                'method' => 'Realizar ' . $this->resource['meta']['method'] . ' request a la upload_url',
                'headers' => 'Incluir los headers especificados',
                'body' => 'Enviar el archivo como body raw (no form-data)',
                'confirm' => 'Después del upload exitoso, llamar al endpoint de confirmación con el file_id',
            ],
        ];
    }
}
