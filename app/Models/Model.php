<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base Model para todos los modelos de la aplicación
 *
 * Proporciona configuración común y funcionalidad base
 * que será extendida por todos los modelos del sistema.
 */
class Model extends EloquentModel
{
    /**
     * Nombre de la conexión de base de datos por defecto
     *
     * @var string
     */
    protected $connection = 'apygg';

    /**
     * Indica si el modelo debe usar timestamps
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Formato de fecha para serialización
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * Los atributos que deben ser convertidos a tipos nativos
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

