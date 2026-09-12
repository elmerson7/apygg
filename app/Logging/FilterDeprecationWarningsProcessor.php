<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * FilterDeprecationWarningsProcessor
 *
 * Variante processor del filtro de deprecaciones, para canales donde no se
 * construye el handler a mano (stderr, papertrail, etc.). Devolver `null`
 * hace que Monolog descarte el registro.
 *
 * Uso en config/logging.php:
 *   'processors' => [\App\Logging\FilterDeprecationWarningsProcessor::class],
 */
class FilterDeprecationWarningsProcessor implements ProcessorInterface
{
    use FiltersDeprecationWarnings;

    public function __invoke(LogRecord $record): ?LogRecord
    {
        return $this->shouldFilter($record) ? null : $record;
    }
}
