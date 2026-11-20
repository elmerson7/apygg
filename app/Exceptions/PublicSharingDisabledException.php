<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El ajuste `security.allow_public_sharing` está apagado y se intentó hacer público un archivo:
 * 403 con `code` = `public_sharing_disabled`.
 */
class PublicSharingDisabledException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Los archivos públicos están desactivados (security.allow_public_sharing)', 403);
    }

    public function errorCode(): ErrorCode|string
    {
        return 'public_sharing_disabled';
    }
}
