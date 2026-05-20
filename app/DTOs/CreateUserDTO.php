<?php

namespace App\DTOs;

/**
 * CreateUserDTO
 *
 * Data Transfer Object para crear nuevos usuarios.
 */
class CreateUserDTO
{
    /**
     * Nombre del usuario
     */
    public string $name;

    /**
     * Email del usuario
     */
    public string $email;

    /**
     * Contraseña del usuario
     */
    public string $password;

    /**
     * Timezone del usuario (opcional)
     */
    public ?string $timezone;

    /**
     * Documento de identidad (opcional)
     */
    public ?string $identity_document;

    /**
     * Proveedor de autenticación (opcional)
     */
    public ?string $provider;

    /**
     * ID del proveedor (opcional)
     */
    public ?string $provider_id;

    /**
     * Preferencias del usuario (opcional)
     */
    public ?array $preferences;

    /**
     * Crear una instancia desde un array de datos
     *
     * @param  array  $data  Array con los datos
     */
    public static function fromArray(array $data): self
    {
        $dto = new self;
        $dto->name = $data['name'] ?? '';
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';
        $dto->timezone = $data['timezone'] ?? null;
        $dto->identity_document = $data['identity_document'] ?? null;
        $dto->provider = $data['provider'] ?? null;
        $dto->provider_id = $data['provider_id'] ?? null;
        $dto->preferences = $data['preferences'] ?? null;

        return $dto;
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'timezone' => $this->timezone,
            'identity_document' => $this->identity_document,
            'provider' => $this->provider,
            'provider_id' => $this->provider_id,
            'preferences' => $this->preferences,
        ];
    }
}
