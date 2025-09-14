<?php

namespace App\Logging;

use Monolog\LogRecord;

class PiiMaskingProcessor
{
    /**
     * Campos PII que deben ser enmascarados en los logs
     */
    private array $piiFields = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'api_key', 'secret',
        'authorization', 'x-api-key', 'email', 'phone', 'phone_number',
        'address', 'credit_card', 'card_number', 'cvv', 'ssn',
        'social_security', 'passport', 'dni', 'cedula', 'license',
        'iban', 'account_number'
    ];

    /**
     * Procesa el record de log para enmascarar información PII
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Crear un nuevo record con los datos enmascarados
        return new LogRecord(
            datetime: $record->datetime,
            channel: $record->channel,
            level: $record->level,
            message: $this->maskString($record->message),
            context: $this->maskArray($record->context),
            extra: $this->maskArray($record->extra),
            formatted: $record->formatted
        );
    }

    /**
     * Enmascara array recursivamente
     */
    private function maskArray(array $data): array
    {
        $maskedData = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $maskedData[$key] = $this->maskArray($value);
            } elseif (is_string($value)) {
                if ($this->isPiiField($key)) {
                    $maskedData[$key] = '[MASKED]';
                } else {
                    $maskedData[$key] = $this->maskString($value);
                }
            } else {
                $maskedData[$key] = $value;
            }
        }

        return $maskedData;
    }

    /**
     * Enmascara strings con patrones PII
     */
    private function maskString(string $string): string
    {
        // Emails
        $string = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[EMAIL]', $string);
        
        // Tokens Bearer
        $string = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer [TOKEN]', $string);
        
        // Tarjetas de crédito (formato básico)
        $string = preg_replace('/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', '[CARD]', $string);
        
        // SSN
        $string = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]', $string);

        // Passwords en URLs o texto
        $string = preg_replace('/password[=:]\s*[^\s&\]]+/i', 'password=[MASKED]', $string);

        return $string;
    }

    /**
     * Verifica si un campo es PII
     */
    private function isPiiField(string $field): bool
    {
        $field = strtolower($field);
        
        foreach ($this->piiFields as $piiField) {
            if (str_contains($field, $piiField)) {
                return true;
            }
        }

        return false;
    }
}
