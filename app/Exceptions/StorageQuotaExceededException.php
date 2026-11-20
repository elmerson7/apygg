<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El tenant no tiene cuota de almacenamiento para el archivo (`max_storage_mb` del plan):
 * 403 con `code` = `storage_quota_exceeded` y `limit_bytes`, `used_bytes`, `adding_bytes`.
 */
class StorageQuotaExceededException extends ApiException
{
    public function __construct(int $limitBytes, int $usedBytes, int $addingBytes)
    {
        parent::__construct(
            'Has alcanzado la cuota de almacenamiento de tu plan',
            403,
            [],
            null,
            null,
            ['limit_bytes' => $limitBytes, 'used_bytes' => $usedBytes, 'adding_bytes' => $addingBytes],
        );
    }

    public function errorCode(): ErrorCode|string
    {
        return 'storage_quota_exceeded';
    }
}
