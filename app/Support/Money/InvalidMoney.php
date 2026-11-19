<?php

namespace App\Support\Money;

use InvalidArgumentException;

/** Importe mal formado, fuera de rango o con más decimales de los que admite la moneda. */
final class InvalidMoney extends InvalidArgumentException {}
