<?php

namespace App\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * FilterDeprecationWarningsHandler
 *
 * Handler decorador que envuelve otro handler de Monolog y descarta los warnings
 * de deprecación provenientes de dependencias externas (vendor), que son ruido
 * conocido de compatibilidad con PHP 8.5 y no indican un problema de la app.
 *
 * Los patrones a filtrar se configuran en `config/logging.php` →
 * `deprecation_filter.patterns`; si no hay configuración se usan los de aquí.
 *
 * Uso (ver AppServiceProvider::configureDateOrganizedLogChannels):
 *   $handler = new FilterDeprecationWarningsHandler($baseHandler);
 */
class FilterDeprecationWarningsHandler implements HandlerInterface
{
    use FiltersDeprecationWarnings;

    public function __construct(
        private HandlerInterface $handler
    ) {}

    public function isHandling(LogRecord $record): bool
    {
        if ($this->shouldFilter($record)) {
            return false;
        }

        return $this->handler->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        return $this->handler->handle($record);
    }

    /**
     * @param  array<int, LogRecord>  $records
     */
    public function handleBatch(array $records): void
    {
        $filtered = [];

        foreach ($records as $record) {
            if ($this->isHandling($record)) {
                $filtered[] = $record;
            }
        }

        if (! empty($filtered)) {
            $this->handler->handleBatch($filtered);
        }
    }

    public function close(): void
    {
        $this->handler->close();
    }
}
