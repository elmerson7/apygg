<?php

namespace App\Support\Sequences;

use RuntimeException;

/**
 * La secuencia llegó a su valor máximo (`max_value`): no se reparten más números.
 */
final class SequenceExhausted extends RuntimeException {}
