<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Multiempresa apagado con datos de varias empresas: error de despliegue (500 deliberado, nunca datos cruzados).
 */
class TenancyMisconfigured extends RuntimeException {}
