<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Clave de límite no declarada en `config/plans.php` (docs/settings-and-flags.md §Límites): 422 con
 * `code` = `plan_limit_unknown`.
 */
class PlanLimitUnknownException extends ApiException
{
    public static function forKey(string $key): self
    {
        return new self("Límite no declarado en config/plans.php: {$key}", 422, [], null, null, ['limit_key' => $key]);
    }

    public function errorCode(): ErrorCode|string
    {
        return 'plan_limit_unknown';
    }
}
