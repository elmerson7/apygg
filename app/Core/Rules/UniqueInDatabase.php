<?php

namespace App\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Regla de validación para verificar unicidad en base de datos
 * 
 * Valida que un valor sea único en una tabla específica.
 */
class UniqueInDatabase implements ValidationRule
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
     * ID a ignorar (para actualizaciones)
     */
    protected ?string $ignoreId = null;

    /**
     * Columna de ID a ignorar
     */
    protected string $ignoreColumn = 'id';

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
     * Ignorar un ID específico (útil para actualizaciones)
     */
    public function ignore($id, string $column = 'id'): self
    {
        $this->ignoreId = $id;
        $this->ignoreColumn = $column;
        return $this;
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

        // Ignorar ID específico si se proporciona
        if ($this->ignoreId !== null) {
            $query->where($this->ignoreColumn, '!=', $this->ignoreId);
        }

        // Aplicar condiciones adicionales
        foreach ($this->wheres as $where) {
            $query->where($where['column'], $where['value']);
        }

        if ($query->exists()) {
            $fail('El valor del campo :attribute ya está en uso.');
        }
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'El valor del campo :attribute ya está en uso.';
    }
}
