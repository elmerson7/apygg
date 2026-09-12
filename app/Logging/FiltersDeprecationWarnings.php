<?php

namespace App\Logging;

use Monolog\LogRecord;

/**
 * FiltersDeprecationWarnings
 *
 * Lógica compartida por el handler y el processor de filtrado de deprecaciones,
 * para no duplicar la lista de patrones ni el algoritmo de coincidencia.
 */
trait FiltersDeprecationWarnings
{
    /**
     * Patrones por defecto (dependencias externas con ruido conocido en PHP 8.5).
     *
     * @var array<int, string>
     */
    private const DEFAULT_FILTER_PATTERNS = [
        // Laravel Framework — paso de null a funciones de string
        '/str_replace\(\): Passing null to parameter/',
        '/mb_strtoupper\(\): Passing null to parameter/',
        '/Using null as an array offset is deprecated/',
        '/preg_match_all\(\): Passing null to parameter/',
        // Scramble — null en array offsets al generar la documentación
        '/vendor\/dedoc\/scramble/',
        // brick/math — floats en BigNumber::of()
        '/Passing floats to BigNumber::of\(\)/',
        // Laravel Framework — helpers y Str
        '/vendor\/laravel\/framework\/src\/Illuminate\/Support\/helpers.php/',
        '/vendor\/laravel\/framework\/src\/Illuminate\/Support\/Str.php/',
    ];

    /**
     * Nivel mínimo a filtrar (300 = WARNING). Por debajo no se toca nada.
     */
    private const MIN_LEVEL = 300;

    /**
     * Patrones activos: los de config si existen, si no los del trait.
     *
     * @return array<int, string>
     */
    protected function filterPatterns(): array
    {
        $configured = config('logging.deprecation_filter.patterns');

        return is_array($configured) && ! empty($configured)
            ? $configured
            : self::DEFAULT_FILTER_PATTERNS;
    }

    /**
     * ¿Este registro debe descartarse?
     */
    protected function shouldFilter(LogRecord $record): bool
    {
        if ($record->level->value < self::MIN_LEVEL) {
            return false;
        }

        if (! config('logging.deprecation_filter.enabled', true)) {
            return false;
        }

        $context = $record->context;
        $contextString = json_encode($context);

        $trace = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $trace = $context['exception']->getTraceAsString();
        }

        foreach ($this->filterPatterns() as $pattern) {
            if (preg_match($pattern, $record->message)) {
                return true;
            }

            if ($trace !== null && preg_match($pattern, $trace)) {
                return true;
            }

            if ($contextString !== false && preg_match($pattern, $contextString)) {
                return true;
            }
        }

        return false;
    }
}
