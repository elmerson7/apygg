<?php

namespace App\Helpers;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * DateHelper
 *
 * Helper estático para operaciones con fechas y timezones.
 * Utiliza Carbon para todas las operaciones de fecha.
 */
class DateHelper
{
    /**
     * Formatear fecha según formato estándar
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a formatear
     * @param  string  $format  Formato (default: 'Y-m-d H:i:s')
     * @param  string|null  $timezone  Timezone destino (opcional)
     * @return string|null Fecha formateada o null si es inválida
     */
    public static function format($date, string $format = 'Y-m-d H:i:s', ?string $timezone = null): ?string
    {
        if ($date === null) {
            return null;
        }

        try {
            $carbon = Carbon::parse($date);

            if ($timezone) {
                $carbon = $carbon->setTimezone($timezone);
            }

            return $carbon->format($format);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Formatear fecha en formato ISO 8601
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a formatear
     * @param  string|null  $timezone  Timezone destino (opcional)
     * @return string|null Fecha en formato ISO 8601
     */
    public static function toIso8601($date, ?string $timezone = null): ?string
    {
        return self::format($date, Carbon::ATOM, $timezone);
    }

    /**
     * Formatear fecha en formato legible en español
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a formatear
     * @param  bool  $includeTime  Si incluir hora
     * @param  string|null  $timezone  Timezone destino (opcional)
     * @return string|null Fecha formateada en español
     */
    public static function toSpanish($date, bool $includeTime = false, ?string $timezone = null): ?string
    {
        if ($date === null) {
            return null;
        }

        try {
            $carbon = Carbon::parse($date);

            if ($timezone) {
                $carbon = $carbon->setTimezone($timezone);
            }

            $carbon->locale('es');

            if ($includeTime) {
                return $carbon->translatedFormat('d \d\e F \d\e Y \a \l\a\s H:i');
            }

            return $carbon->translatedFormat('d \d\e F \d\e Y');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Formatear fecha en formato corto (d/m/Y)
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a formatear
     * @param  string|null  $timezone  Timezone destino (opcional)
     * @return string|null Fecha en formato corto
     */
    public static function toShort($date, ?string $timezone = null): ?string
    {
        return self::format($date, 'd/m/Y', $timezone);
    }

    /**
     * Formatear fecha con hora corta (d/m/Y H:i)
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a formatear
     * @param  string|null  $timezone  Timezone destino (opcional)
     * @return string|null Fecha con hora en formato corto
     */
    public static function toShortWithTime($date, ?string $timezone = null): ?string
    {
        return self::format($date, 'd/m/Y H:i', $timezone);
    }

    /**
     * Convertir fecha a otro timezone
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a convertir
     * @param  string  $toTimezone  Timezone destino
     * @param  string|null  $fromTimezone  Timezone origen (opcional, usa el de la fecha si no se especifica)
     * @return Carbon|null Fecha convertida o null si es inválida
     */
    public static function convertTimezone($date, string $toTimezone, ?string $fromTimezone = null): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        try {
            $carbon = Carbon::parse($date);

            if ($fromTimezone) {
                $carbon = $carbon->setTimezone($fromTimezone);
            }

            return $carbon->setTimezone($toTimezone);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener diferencia de tiempo en formato legible
     *
     * @param  DateTimeInterface|string|null  $from  Fecha inicial
     * @param  DateTimeInterface|string|null  $to  Fecha final (default: ahora)
     * @param  bool  $absolute  Si la diferencia debe ser absoluta
     * @return string|null Diferencia en formato legible (ej: "2 horas", "3 días")
     */
    public static function diffForHumans($from, $to = null, bool $absolute = false): ?string
    {
        if ($from === null) {
            return null;
        }

        try {
            $fromCarbon = Carbon::parse($from);
            $toCarbon = $to ? Carbon::parse($to) : Carbon::now();

            $fromCarbon->locale('es');

            return $fromCarbon->diffForHumans($toCarbon, ['absolute' => $absolute]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener diferencia de tiempo en segundos
     *
     * @param  DateTimeInterface|string|null  $from  Fecha inicial
     * @param  DateTimeInterface|string|null  $to  Fecha final (default: ahora)
     * @return int|null Diferencia en segundos
     */
    public static function diffInSeconds($from, $to = null): ?int
    {
        if ($from === null) {
            return null;
        }

        try {
            $fromCarbon = Carbon::parse($from);
            $toCarbon = $to ? Carbon::parse($to) : Carbon::now();

            return (int) $fromCarbon->diffInSeconds($toCarbon);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener diferencia de tiempo en minutos
     *
     * @param  DateTimeInterface|string|null  $from  Fecha inicial
     * @param  DateTimeInterface|string|null  $to  Fecha final (default: ahora)
     * @return int|null Diferencia en minutos
     */
    public static function diffInMinutes($from, $to = null): ?int
    {
        if ($from === null) {
            return null;
        }

        try {
            $fromCarbon = Carbon::parse($from);
            $toCarbon = $to ? Carbon::parse($to) : Carbon::now();

            return (int) $fromCarbon->diffInMinutes($toCarbon);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener diferencia de tiempo en horas
     *
     * @param  DateTimeInterface|string|null  $from  Fecha inicial
     * @param  DateTimeInterface|string|null  $to  Fecha final (default: ahora)
     * @return int|null Diferencia en horas
     */
    public static function diffInHours($from, $to = null): ?int
    {
        if ($from === null) {
            return null;
        }

        try {
            $fromCarbon = Carbon::parse($from);
            $toCarbon = $to ? Carbon::parse($to) : Carbon::now();

            return (int) $fromCarbon->diffInHours($toCarbon);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener diferencia de tiempo en días
     *
     * @param  DateTimeInterface|string|null  $from  Fecha inicial
     * @param  DateTimeInterface|string|null  $to  Fecha final (default: ahora)
     * @return int|null Diferencia en días
     */
    public static function diffInDays($from, $to = null): ?int
    {
        if ($from === null) {
            return null;
        }

        try {
            $fromCarbon = Carbon::parse($from);
            $toCarbon = $to ? Carbon::parse($to) : Carbon::now();

            return (int) $fromCarbon->diffInDays($toCarbon);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parsear fecha desde múltiples formatos
     *
     * @param  string  $dateString  String de fecha a parsear
     * @param  array  $formats  Formatos a intentar (opcional)
     * @return Carbon|null Fecha parseada o null si falla
     */
    public static function parse(string $dateString, array $formats = []): ?Carbon
    {
        if (empty($formats)) {
            // Formatos comunes por defecto
            $formats = [
                'Y-m-d H:i:s',
                'Y-m-d',
                'd/m/Y H:i:s',
                'd/m/Y',
                'Y-m-d\TH:i:s\Z',
                'Y-m-d\TH:i:s.u\Z',
                Carbon::ATOM,
                Carbon::ISO8601,
            ];
        }

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateString);
            } catch (\Exception $e) {
                continue;
            }
        }

        // Intentar parse automático de Carbon
        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtener rango de fechas
     *
     * @param  DateTimeInterface|string|null  $start  Fecha inicial
     * @param  DateTimeInterface|string|null  $end  Fecha final
     * @param  string  $format  Formato de salida
     * @return array|null Array con 'start' y 'end' o null si es inválido
     */
    public static function getDateRange($start, $end, string $format = 'Y-m-d'): ?array
    {
        if ($start === null || $end === null) {
            return null;
        }

        try {
            $startCarbon = Carbon::parse($start);
            $endCarbon = Carbon::parse($end);

            if ($startCarbon->gt($endCarbon)) {
                return null; // Fecha inicial mayor que final
            }

            return [
                'start' => $startCarbon->format($format),
                'end' => $endCarbon->format($format),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verificar si una fecha está dentro de un rango
     *
     * @param  DateTimeInterface|string|null  $date  Fecha a verificar
     * @param  DateTimeInterface|string|null  $start  Fecha inicial del rango
     * @param  DateTimeInterface|string|null  $end  Fecha final del rango
     * @param  bool  $inclusive  Si incluir los límites
     * @return bool|null true si está dentro del rango, false si no, null si es inválido
     */
    public static function isWithinRange($date, $start, $end, bool $inclusive = true): ?bool
    {
        if ($date === null || $start === null || $end === null) {
            return null;
        }

        try {
            $dateCarbon = Carbon::parse($date);
            $startCarbon = Carbon::parse($start);
            $endCarbon = Carbon::parse($end);

            if ($inclusive) {
                return $dateCarbon->between($startCarbon, $endCarbon);
            }

            return $dateCarbon->gt($startCarbon) && $dateCarbon->lt($endCarbon);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validar formato de fecha
     *
     * @param  string  $dateString  String de fecha a validar
     * @param  string  $format  Formato esperado
     * @return bool true si el formato es válido
     */
    public static function validateFormat(string $dateString, string $format): bool
    {
        try {
            $date = Carbon::createFromFormat($format, $dateString);

            return $date && $date->format($format) === $dateString;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtener fecha actual en timezone específico
     *
     * @param  string|null  $timezone  Timezone (default: config('app.timezone'))
     * @return Carbon Fecha actual
     */
    public static function now(?string $timezone = null): Carbon
    {
        $timezone = $timezone ?? config('app.timezone', 'UTC');

        return Carbon::now($timezone);
    }

    /**
     * Obtener fecha de hoy (sin hora)
     *
     * @param  string|null  $timezone  Timezone (default: config('app.timezone'))
     * @return Carbon Fecha de hoy a las 00:00:00
     */
    public static function today(?string $timezone = null): Carbon
    {
        $timezone = $timezone ?? config('app.timezone', 'UTC');

        return Carbon::today($timezone);
    }

    /**
     * Obtener fecha de ayer
     *
     * @param  string|null  $timezone  Timezone (default: config('app.timezone'))
     * @return Carbon Fecha de ayer
     */
    public static function yesterday(?string $timezone = null): Carbon
    {
        $timezone = $timezone ?? config('app.timezone', 'UTC');

        return Carbon::yesterday($timezone);
    }

    /**
     * Obtener fecha de mañana
     *
     * @param  string|null  $timezone  Timezone (default: config('app.timezone'))
     * @return Carbon Fecha de mañana
     */
    public static function tomorrow(?string $timezone = null): Carbon
    {
        $timezone = $timezone ?? config('app.timezone', 'UTC');

        return Carbon::tomorrow($timezone);
    }
}
