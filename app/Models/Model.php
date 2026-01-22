<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base Model
 * 
 * Clase base para todos los modelos de la aplicación.
 * Proporciona funcionalidad común para todos los modelos.
 */
abstract class Model extends EloquentModel
{
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the table name for the model.
     */
    public function getTableName(): string
    {
        return $this->getTable();
    }
}
