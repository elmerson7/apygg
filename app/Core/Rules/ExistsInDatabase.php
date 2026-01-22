<?php

namespace App\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Regla de validación para verificar existencia en base de datos
 * 
 * Valida que un valor exista en una tabla específica.
 */
class ExistsInDatabase implements ValidationRule
{
    /**
     * Tabla a verificar
     */
    protected string $table;

    /**
     * Columna a verificar
     */
    protected string $column;

    /**
     * Condiciones adicionales
     */
    protected array $wheres = [];

    /**
     * Crear instancia
     */
    public function __construct(string $table, string $column = 'id')
    {
        $this->table = $table;
        $this->column = $column;
    }

    /**
     * Agregar condición WHERE adicional
     */
    public function where(string $column, $value): self
    {
        $this->wheres[] = compact('column', 'value');
        return $this;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = DB::table($this->table)
            ->where($this->column, $value);

        // Aplicar condiciones adicionales
        foreach ($this->wheres as $where) {
            $query->where($where['column'], $where['value']);
        }

        if (!$query->exists()) {
            $fail('El valor seleccionado para :attribute no es válido.');
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El valor seleccionado para :attribute no existe.';
    }
}
