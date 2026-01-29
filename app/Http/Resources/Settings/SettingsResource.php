<?php

namespace App\Http\Resources\Settings;

use App\Http\Resources\BaseResource;
use App\Services\SettingsService;
use Illuminate\Http\Request;

/**
 * SettingsResource
 *
 * Resource para transformación de datos de Settings.
 * Desencripta automáticamente valores encriptados al mostrar.
 */
class SettingsResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $setting = $this->resource;
        $settingsService = app(SettingsService::class);

        // Obtener valor desencriptado si está encriptado
        $value = $setting->is_encrypted
            ? $settingsService->decryptValue($setting->value)
            : $setting->value;

        // Convertir valor al tipo correcto
        $typedValue = match ($setting->type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'boolean' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'array' => is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : $value),
            default => $value,
        };

        return array_merge($this->getBaseFields(), [
            'key' => $setting->key,
            'value' => $typedValue,
            'type' => $setting->type,
            'group' => $setting->group,
            'description' => $setting->description,
            'is_public' => $setting->is_public,
            'is_encrypted' => $setting->is_encrypted,
            'created_at' => $this->formatDate($setting->created_at),
            'updated_at' => $this->formatDate($setting->updated_at),
        ]);
    }
}
