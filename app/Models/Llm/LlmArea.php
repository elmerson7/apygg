<?php

namespace App\Models\Llm;

use App\Models\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * LlmArea
 *
 * Un modelo principal + N fallbacks por "área" del sistema.
 *
 * El área es la unidad de configuración: agrupa los casos de uso que comparten
 * exigencia y presupuesto (por ejemplo `soporte`, `auditoria`, `docs`). Elegir
 * el modelo desde la BD y no desde el código permite cambiarlo sin desplegar,
 * que es justo lo que hace falta cuando un proveedor sube precios, degrada o
 * saca un modelo mejor.
 *
 * No se configuran parámetros del modelo (temperature, max_tokens): cada modelo
 * trae los suyos y sobrescribirlos a ciegas empeora el resultado.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $primary_provider
 * @property string|null $primary_model
 * @property array<int, mixed>|null $fallbacks
 * @property bool $is_active
 * @property int $sort_order
 */
class LlmArea extends Model
{
    protected $table = 'llm_areas';

    protected $fillable = [
        'key',
        'name',
        'description',
        'primary_provider',
        'primary_model',
        'fallbacks',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'fallbacks' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Cadena de modelos a probar en orden: el principal y después los
     * fallbacks que tengan modelo definido.
     *
     * @return array<int, array{provider: string, model: string}>
     */
    public function chain(): array
    {
        $chain = [];

        if (is_string($this->primary_model) && $this->primary_model !== '') {
            $chain[] = [
                'provider' => $this->primary_provider,
                'model' => $this->primary_model,
            ];
        }

        foreach ($this->fallbacks ?? [] as $fallback) {
            $model = is_array($fallback) ? ($fallback['model'] ?? null) : null;

            if (is_string($model) && $model !== '') {
                $chain[] = [
                    'provider' => (string) ($fallback['provider'] ?? $this->primary_provider),
                    'model' => $model,
                ];
            }
        }

        return $chain;
    }

    /**
     * ¿Está el área lista para usarse?
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->chain() !== [];
    }
}
