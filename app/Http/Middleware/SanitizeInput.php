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
     * Campos que preservan saltos de línea (no se normalizan espacios)
     * Específicamente para endpoints de clases donde description puede tener formato
     */
    private array $preserveNewlinesFields = [
        'description',
    ];

    /**
     * Rutas donde se preservan saltos de línea para ciertos campos
     */
    private array $preserveNewlinesRoutes = [
        'admin/events/*/modules/*/classes',
        'admin/events/*/modules/*/classes/*',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Determinar si esta ruta preserva saltos de línea
        $this->shouldPreserveNewlines = $this->checkPreserveNewlines($request);

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

    private bool $shouldPreserveNewlines = false;

    /**
     * Verificar si la ruta actual debe preservar saltos de línea
     */
    private function checkPreserveNewlines(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->preserveNewlinesRoutes as $pattern) {
            $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
            if (preg_match("/^{$regex}$/", $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si el campo debe preservar saltos de línea
     */
    private function shouldPreserveNewlinesForField(string $field): bool
    {
        return $this->shouldPreserveNewlines
            && in_array(strtolower($field), $this->preserveNewlinesFields, true);
    }

    /**
     * Sanitizar array recursivamente
     */
    private function sanitizeArray(array $data, $target, ?string $parentField = null): void
    {
        foreach ($data as $key => $value) {
            // Saltar campos excluidos
            if (in_array(strtolower($key), $this->excludedFields, true)) {
                continue;
            }

            if (is_array($value)) {
                // Recursión para arrays anidados
                $this->sanitizeArray($value, $target, $key);
            } elseif (is_string($value)) {
                // Sanitizar strings
                $sanitized = $this->sanitizeString($value, $key);

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
    private function sanitizeString(string $value, string $field = ''): string
    {
        // 1. Trim espacios en blanco
        $value = trim($value);

        // 2. Eliminar caracteres de control (excepto tab, newline, carriage return)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // 3. Eliminar tags HTML peligrosos (pero mantener estructura básica si es necesario)
        // Para campos de texto plano, eliminar todo HTML
        $value = SecurityHelper::sanitizeHtml($value);

        // 4. Normalizar espacios múltiples a uno solo (pero preservar saltos de línea si es necesario)
        if ($this->shouldPreserveNewlinesForField($field)) {
            // Preservar saltos de línea: solo normalizar espacios que no sean newlines
            $value = preg_replace('/[ \t]+/', ' ', $value);
        } else {
            $value = preg_replace('/\s+/', ' ', $value);
        }

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
