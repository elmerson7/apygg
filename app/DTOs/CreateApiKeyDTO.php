<?php

namespace App\DTOs;

/**
 * CreateApiKeyDTO
 *
 * Data Transfer Object para crear nuevas API Keys.
 */
class CreateApiKeyDTO
{
    /**
     * Nombre descriptivo de la API Key
     */
    public string $name;

    /**
     * Scopes permitidos para la API Key (opcional)
     */
    public ?array $scopes;

    /**
     * Fecha de expiración (opcional)
     */
    public ?string $expires_at;

    /**
     * Entorno (live o test)
     */
    public string $environment;

    /**
     * Crear una instancia desde un array de datos
     *
     * @param  array  $data  Array con los datos
     */
    public static function fromArray(array $data): self
    {
        $dto = new self;
        $dto->name = $data['name'] ?? '';
        $dto->scopes = $data['scopes'] ?? null;
        $dto->expires_at = $data['expires_at'] ?? null;
        $dto->environment = $data['environment'] ?? 'live';

        return $dto;
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'scopes' => $this->scopes,
            'expires_at' => $this->expires_at,
            'environment' => $this->environment,
        ];
    }
}
