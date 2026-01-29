<?php

namespace App\Models;

/**
 * Settings Model
 *
 * Modelo para configuraciones del sistema.
 * Permite almacenar valores configurables con soporte para tipos de datos,
 * grupos, encriptación y visibilidad pública.
 */
class Settings extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
        'is_public',
        'is_encrypted',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_public' => 'boolean',
        'is_encrypted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Columnas buscables por defecto para el scope search()
     *
     * @return array<string>
     */
    protected function getSearchableColumns(): array
    {
        return ['key', 'group', 'description'];
    }

    /**
     * Scope para filtrar por grupo
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $group  Grupo a filtrar
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope para filtrar solo settings públicos
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope para filtrar solo settings encriptados
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEncrypted($query)
    {
        return $query->where('is_encrypted', true);
    }

    /**
     * Scope para buscar por key exacta
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $key  Key a buscar
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Obtener valor desencriptado si está encriptado
     *
     * @return mixed Valor desencriptado o el valor original
     */
    public function getDecryptedValue()
    {
        if (! $this->is_encrypted) {
            return $this->value;
        }

        try {
            return \App\Services\SecurityService::decrypt($this->value);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to decrypt setting value', [
                'setting_id' => $this->id,
                'key' => $this->key,
                'error' => $e->getMessage(),
            ]);

            return $this->value;
        }
    }

    /**
     * Verificar si el setting es público
     */
    public function isPublic(): bool
    {
        return $this->is_public === true;
    }

    /**
     * Verificar si el setting está encriptado
     */
    public function isEncrypted(): bool
    {
        return $this->is_encrypted === true;
    }

    /**
     * Obtener valor con tipo correcto según campo type
     *
     * @return mixed Valor con tipo correcto
     */
    public function getTypedValue()
    {
        $value = $this->getDecryptedValue();

        return match ($this->type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'boolean' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'array' => is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : $value),
            default => $value,
        };
    }
}
