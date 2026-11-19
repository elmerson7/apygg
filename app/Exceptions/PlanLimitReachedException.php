<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * El tenant alcanzó un límite de su plan (docs/settings-and-flags.md §Límites). 403 con
 * `code` = `plan_limit_reached` y `limit`/`current` en el cuerpo. No es un cobro: solo un tope.
 */
class PlanLimitReachedException extends ApiException
{
    public function __construct(public readonly string $limitKey, public readonly int $limit, public readonly int $current)
    {
        parent::__construct(
            "Has alcanzado el límite de tu plan ({$limitKey}: {$limit})",
            403,
            [],
            null,
            null,
            ['limit_key' => $limitKey, 'limit' => $limit, 'current' => $current],
        );
    }

    public function errorCode(): ErrorCode|string
    {
        return 'plan_limit_reached';
    }
}
