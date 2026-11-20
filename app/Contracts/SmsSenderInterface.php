<?php

namespace App\Contracts;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Sms\SmsMessage;
use App\Integrations\Sms\SmsResult;

/**
 * SmsSenderInterface
 *
 * Envío de SMS. Lo que necesita la app, no el SDK de un proveedor. La implementación se elige
 * por `SMS_MODE` (`real|fake`) en `IntegrationsServiceProvider` (docs/integrations.md).
 */
interface SmsSenderInterface
{
    /**
     * @throws IntegrationException Transient/RateLimited (reintentables), Quota/Permanent/InvalidOutput (no)
     */
    public function send(SmsMessage $message): SmsResult;
}
