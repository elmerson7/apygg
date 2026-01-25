<?php

namespace App\Http\Middleware;

use App\Helpers\SecurityHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SanitizeInput Middleware
 *
 * Middleware para limpiar y sanitizar todos los inputs de la request
 * antes de que lleguen a los controladores. Previene XSS y limpia datos.
 */
class SanitizeInput
{
    /**
     * Campos que NO deben ser sanitizados (por ejemplo, contraseñas, tokens)
     * Estos campos se procesan de forma especial o se mantienen intactos
     */
    private array $excludedFields = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_key',
        'secret',
        'private_key',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Sanitizar solo si hay datos en el request
        if ($request->isMethod('GET')) {
            // Para GET, sanitizar query parameters
            $this->sanitizeArray($request->query->all(), $request->query);
        } else {
            // Para POST, PUT, PATCH, DELETE, sanitizar body
            $this->sanitizeArray($request->all(), $request);
        }

        return $next($request);
    }

    /**
     * Sanitizar array recursivamente
     */
    private function sanitizeArray(array $data, $target): void
    {
        foreach ($data as $key => $value) {
            // Saltar campos excluidos
            if (in_array(strtolower($key), $this->excludedFields, true)) {
                continue;
            }

            if (is_array($value)) {
                // Recursión para arrays anidados
                $this->sanitizeArray($value, $target);
            } elseif (is_string($value)) {
                // Sanitizar strings
                $sanitized = $this->sanitizeString($value);

                // Actualizar el valor sanitizado
                if ($target instanceof Request) {
                    $requestData = $target->all();
                    $this->setNestedValue($requestData, $key, $sanitized);
                    $target->merge($requestData);
                } else {
                    $target->set($key, $sanitized);
                }
            }
        }
    }

    /**
     * Sanitizar string individual
     */
    private function sanitizeString(string $value): string
    {
        // 1. Trim espacios en blanco
        $value = trim($value);

        // 2. Eliminar caracteres de control (excepto tab, newline, carriage return)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // 3. Eliminar tags HTML peligrosos (pero mantener estructura básica si es necesario)
        // Para campos de texto plano, eliminar todo HTML
        $value = SecurityHelper::sanitizeHtml($value);

        // 4. Normalizar espacios múltiples a uno solo
        $value = preg_replace('/\s+/', ' ', $value);

        // 5. Convertir strings vacíos a null (se manejará después)
        if ($value === '') {
            return '';
        }

        return $value;
    }

    /**
     * Establecer valor en array anidado
     */
    private function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (! isset($current[$k]) || ! is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }
}
