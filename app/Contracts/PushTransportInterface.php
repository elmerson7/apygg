<?php

namespace App\Contracts;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Push\PushMessage;
use App\Integrations\Push\PushResult;

/**
 * PushTransportInterface
 *
 * Envío de UNA notificación push a UN token de dispositivo. La implementación se elige por
 * `PUSH_MODE` (`real|fake`) en `IntegrationsServiceProvider` (docs/integrations.md).
 */
interface PushTransportInterface
{
    /**
     * @throws IntegrationException Transient/RateLimited (reintentables); `PushTokenInvalidError` si el
     *                              token ya no existe (hay que borrar el dispositivo); el resto, permanentes
     */
    public function send(PushMessage $message): PushResult;
}
