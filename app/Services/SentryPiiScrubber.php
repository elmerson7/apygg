<?php

namespace App\Services;

use Sentry\Event;
use Sentry\EventHint;

class SentryPiiScrubber
{
    /**
     * Campos PII que deben ser limpiados
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
     * Callback para before_send de Sentry
     */
    public function __invoke(Event $event, EventHint $hint): ?Event
    {
        // Limpiar datos de request
        if ($request = $event->getRequest()) {
            $request = $this->scrubRequest($request);
            $event->setRequest($request);
        }

        // Limpiar contexto de usuario
        if ($user = $event->getUser()) {
            $user = $this->scrubUser($user);
            $event->setUser($user);
        }

        // Limpiar contextos extra
        $contexts = $event->getContexts();
        foreach ($contexts as $key => $context) {
            $contexts[$key] = $this->scrubArray($context);
        }
        $event->setContexts($contexts);

        // Limpiar tags
        $tags = $event->getTags();
        $event->setTags($this->scrubArray($tags));

        // Limpiar extra
        $extra = $event->getExtra();
        $event->setExtra($this->scrubArray($extra));

        return $event;
    }

    /**
     * Limpia datos de la request
     */
    private function scrubRequest(array $request): array
    {
        // Limpiar datos POST/JSON
        if (isset($request['data'])) {
            $request['data'] = $this->scrubArray($request['data']);
        }

        // Limpiar headers
        if (isset($request['headers'])) {
            $request['headers'] = $this->scrubArray($request['headers']);
        }

        // Limpiar query parameters
        if (isset($request['query_string'])) {
            $request['query_string'] = $this->scrubString($request['query_string']);
        }

        return $request;
    }

    /**
     * Limpia datos del usuario
     */
    private function scrubUser(array $user): array
    {
        return $this->scrubArray($user);
    }

    /**
     * Limpia array recursivamente
     */
    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            } elseif (is_string($value)) {
                if ($this->isPiiField($key)) {
                    $data[$key] = '[SCRUBBED]';
                } else {
                    $data[$key] = $this->scrubString($value);
                }
            }
        }

        return $data;
    }

    /**
     * Limpia strings con patrones PII
     */
    private function scrubString(string $string): string
    {
        // Emails
        $string = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[EMAIL]', $string);
        
        // Tokens Bearer
        $string = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer [TOKEN]', $string);
        
        // Tarjetas de crédito
        $string = preg_replace('/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/', '[CARD]', $string);
        
        // SSN
        $string = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]', $string);

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
