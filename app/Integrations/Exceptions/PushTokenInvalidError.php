<?php

namespace App\Integrations\Exceptions;

/** El proveedor dice que el token ya no existe (app desinstalada, token rotado): se borra el dispositivo. */
class PushTokenInvalidError extends PermanentIntegrationError {}
