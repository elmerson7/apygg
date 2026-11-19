<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La identidad está en la lista negra: no puede iniciar sesión.
 */
class AccountBlockedException extends RuntimeException {}
