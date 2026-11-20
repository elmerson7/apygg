<?php

namespace App\Logging;

use App\Helpers\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacta mensaje, contexto y extra de cada registro de Monolog con App\Helpers\Redactor.
 * Los objetos del contexto (excepciones...) se dejan intactos.
 */
class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        /** @var array<string, mixed> $context */
        $context = Redactor::redact($record->context);
        /** @var array<string, mixed> $extra */
        $extra = Redactor::redact($record->extra);

        return $record->with(
            message: Redactor::redactString($record->message),
            context: $context,
            extra: $extra,
        );
    }
}
