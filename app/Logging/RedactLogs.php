<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Tap de canal: añade el RedactingProcessor. Se declara en `tap` de cada canal de
 * config/logging.php (también vale para los canales personalizados de AppServiceProvider).
 */
class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new RedactingProcessor);
        }
    }
}
