<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * TransformRequestMiddleware
 *
 * Middleware para normalizar y transformar los datos de entrada
 * antes de que lleguen a los controladores.
 */
class TransformRequestMiddleware
{
    /**
     * Campos que deben convertirse a booleanos
     */
    private array $booleanFields = [
        'is_active',
        'is_enabled',
        'is_verified',
        'is_published',
        'active',
        'enabled',
        'verified',
        'published',
        'status', // Puede ser booleano o string según el caso
    ];

    /**
     * Campos que deben convertirse a números
     */
    private array $numericFields = [
        'id',
        'user_id',
        'amount',
        'price',
        'quantity',
        'count',
        'total',
        'limit',
        'offset',
        'page',
        'per_page',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Transformar solo si hay datos en el request
        if ($request->isMethod('GET')) {
            // Para GET, transformar query parameters
            $this->transformArray($request->query->all(), $request->query);
        } else {
            // Para POST, PUT, PATCH, DELETE, transformar body
            $this->transformArray($request->all(), $request);
        }

        return $next($request);
    }

    /**
     * Transformar array recursivamente
     */
    private function transformArray(array $data, $target): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursión para arrays anidados
                $this->transformArray($value, $target);
            } else {
                $transformed = $this->transformValue($key, $value);
                
                // Actualizar el valor transformado
                if ($target instanceof Request) {
                    $requestData = $target->all();
                    $this->setNestedValue($requestData, $key, $transformed);
                    $target->merge($requestData);
                } else {
                    $target->set($key, $transformed);
                }
            }
        }
    }

    /**
     * Transformar valor individual según su tipo esperado
     */
    private function transformValue(string $key, $value)
    {
        // Convertir strings vacíos a null
        if ($value === '' || $value === null) {
            return null;
        }

        // Normalizar nombre del campo (snake_case)
        $normalizedKey = Str::snake($key);

        // Convertir a booleano si corresponde
        if ($this->shouldBeBoolean($normalizedKey, $value)) {
            return $this->toBoolean($value);
        }

        // Convertir a número si corresponde
        if ($this->shouldBeNumeric($normalizedKey, $value)) {
            return $this->toNumeric($value);
        }

        // Normalizar fechas (ISO 8601)
        if ($this->isDateField($normalizedKey) && is_string($value)) {
            return $this->normalizeDate($value);
        }

        // Normalizar strings (trim ya hecho por SanitizeInput)
        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        return $value;
    }

    /**
     * Verificar si un campo debe ser booleano
     */
    private function shouldBeBoolean(string $key, $value): bool
    {
        // Verificar por nombre del campo
        foreach ($this->booleanFields as $field) {
            if (str_contains($key, $field)) {
                return true;
            }
        }

        // Verificar por valor (si es "true", "false", "1", "0")
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true);
        }

        return false;
    }

    /**
     * Convertir valor a booleano
     */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Verificar si un campo debe ser numérico
     */
    private function shouldBeNumeric(string $key, $value): bool
    {
        // Verificar por nombre del campo
        foreach ($this->numericFields as $field) {
            if (str_ends_with($key, '_' . $field) || $key === $field) {
                return true;
            }
        }

        // Verificar si el valor es numérico
        return is_numeric($value) && !is_string($value) || (is_string($value) && is_numeric(trim($value)));
    }

    /**
     * Convertir valor a numérico
     */
    private function toNumeric($value)
    {
        if (is_numeric($value)) {
            // Si tiene punto decimal, retornar float, sino int
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        return $value;
    }

    /**
     * Verificar si es un campo de fecha
     */
    private function isDateField(string $key): bool
    {
        $dateFields = ['date', 'created_at', 'updated_at', 'deleted_at', 'expires_at', 'published_at'];
        
        foreach ($dateFields as $field) {
            if (str_contains($key, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizar fecha a formato ISO 8601
     */
    private function normalizeDate(string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Intentar parsear la fecha
            $date = Carbon::parse($value);
            return $date->toIso8601String();
        } catch (\Exception $e) {
            // Si no se puede parsear, retornar el valor original
            return $value;
        }
    }

    /**
     * Normalizar string
     */
    private function normalizeString(string $value): string
    {
        // Ya está trimed por SanitizeInput
        // Normalizar espacios múltiples
        return preg_replace('/\s+/', ' ', $value);
    }

    /**
     * Establecer valor en array anidado
     */
    private function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }
}
