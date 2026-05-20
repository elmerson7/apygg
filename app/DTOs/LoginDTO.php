<?php

namespace App\DTOs;

/**
 * LoginDTO
 *
 * Data Transfer Object para el proceso de login.
 */
class LoginDTO
{
    /**
     * Email del usuario
     */
    public string $email;

    /**
     * Contraseña del usuario
     */
    public string $password;

    /**
     * Crear una instancia desde un array de datos
     *
     * @param  array  $data  Array con los datos
     */
    public static function fromArray(array $data): self
    {
        $dto = new self;
        $dto->email = $data['email'] ?? '';
        $dto->password = $data['password'] ?? '';

        return $dto;
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'password' => $this->password,
        ];
    }
}
