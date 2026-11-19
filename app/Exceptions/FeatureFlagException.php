<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Feature flag no declarada en `config/features.php`: 404 con `code` = `feature_unknown`.
 */
class FeatureFlagException extends ApiException
{
    public static function unknown(string $key): self
    {
        return new self("La feature '{$key}' no existe", 404, [], null, null, ['feature' => $key]);
    }

    public function errorCode(): ErrorCode|string
    {
        return 'feature_unknown';
    }
}
