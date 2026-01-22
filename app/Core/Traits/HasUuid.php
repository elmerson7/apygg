<?php

namespace App\Core\Traits;

use Illuminate\Support\Str;

/**
 * Trait HasUuid
 * 
 * Proporciona generación automática de UUID como primary key.
 * 
 * @package App\Core\Traits
 */
trait HasUuid
{
    /**
     * Boot the trait.
     */
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            // Generar UUID si no existe
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Initialize the trait.
     */
    public function initializeHasUuid(): void
    {
        // Configurar primary key como UUID
        $this->setIncrementing(false);
        $this->setKeyType('string');
    }

    /**
     * Generate a new UUID.
     */
    public function generateUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Check if the model has a valid UUID.
     */
    public function hasValidUuid(): bool
    {
        $uuid = $this->getKey();
        
        if (empty($uuid)) {
            return false;
        }

        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $uuid
        );
    }
}
