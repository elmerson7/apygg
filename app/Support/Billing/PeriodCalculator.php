<?php

namespace App\Support\Billing;

use App\Enums\BillingInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calcula el fin de un periodo de cobro sin la deriva de fin de mes: una suscripción anclada el 31 de enero
 * cobra el 28/29 de febrero y vuelve al 31 de marzo (nunca se queda en el 28). Funciones puras, sin estado.
 */
final class PeriodCalculator
{
    public static function end(CarbonInterface $start, CarbonInterface $anchor, BillingInterval $interval): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);
        $anchor = CarbonImmutable::instance($anchor);

        $target = $start->startOfMonth()->addMonthsNoOverflow($interval->months());
        $day = min($anchor->day, $target->daysInMonth);

        return $target->setDate($target->year, $target->month, $day)->setTime($anchor->hour, $anchor->minute, $anchor->second);
    }
}
