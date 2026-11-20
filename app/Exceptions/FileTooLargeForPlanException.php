<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El archivo supera el tamaño máximo por archivo del plan (`max_file_mb`):
 * 413 con `code` = `file_too_large_for_plan` y `limit_bytes`.
 */
class FileTooLargeForPlanException extends ApiException
{
    public function __construct(int $limitBytes, int $sizeBytes)
    {
        parent::__construct(
            'El archivo supera el tamaño máximo permitido por tu plan',
            413,
            [],
            null,
            null,
            ['limit_bytes' => $limitBytes, 'size_bytes' => $sizeBytes],
        );
    }

    public function errorCode(): ErrorCode|string
    {
        return 'file_too_large_for_plan';
    }
}
