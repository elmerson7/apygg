<?php

namespace App\DTOs;

/**
 * RegisterDTO
 *
 * Data Transfer Object para el registro de nuevos usuarios.
 */
class RegisterDTO
{
    /**
     * Nombre del usuario
     *
     * @var string
     */
    public string $name;

    /**
     * Email del usuario
     *
     * @var string
     */
    public string $email;

    /**
     * Contraseña del usuario
     *
     * @var string
     */
    public string $password;

    /**
     * Confirmación de contraseña
     *
     * @var string
     */
    public string $password_confirmation;

    /**
     * Crear una instancia desde un array de datos
     *
     * @param  array  $data  Array con los datos
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->name = $data['name'] ?? '';
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';
        $dto->password_confirmation = $data['password_confirmation'] ?? '';

        return $dto;
    }

    /**
     * Convertir a array (excluyendo password_confirmation para seguridad)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}