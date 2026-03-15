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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $this->transformArray($request->query->all(), $request->query);
        } else {
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
                $this->transformArray($value, $target);
            } else {
                $transformed = $this->transformValue($key, $value);

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
        if ($value === '' || $value === null) {
            return null;
        }

        $normalizedKey = Str::snake($key);

        if ($this->shouldBeBoolean($normalizedKey, $value)) {
            return $this->toBoolean($value);
        }

        if ($this->shouldBeNumeric($normalizedKey, $value)) {
            return $this->toNumeric($value);
        }

        if ($this->isDateField($normalizedKey) && is_string($value)) {
            return $this->normalizeDate($value);
        }

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
        foreach ($this->booleanFields as $field) {
            if (str_contains($key, $field)) {
                return true;
            }
        }

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
     * Excluye UUIDs para evitar conversión incorrecta
     */
    private function shouldBeNumeric(string $key, $value): bool
    {
        // No convertir UUIDs a números
        if (is_string($value) && $this->isUuid($value)) {
            return false;
        }

        foreach ($this->numericFields as $field) {
            if (str_ends_with($key, '_'.$field) || $key === $field) {
                return true;
            }
        }

        return is_numeric($value) && ! is_string($value) || (is_string($value) && is_numeric(trim($value)));
    }

    /**
     * Verificar si un valor es un UUID válido
     */
    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    /**
     * Convertir valor a numérico
     */
    private function toNumeric($value)
    {
        if (is_numeric($value)) {
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
            return Carbon::parse($value)->toIso8601String();
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Normalizar string
     */
    private function normalizeString(string $value): string
    {
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
            if (! isset($current[$k]) || ! is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }
}
