<?php

namespace App\DTOs;

/**
 * UpdateUserDTO
 *
 * Data Transfer Object para actualizar usuarios existentes.
 */
class UpdateUserDTO
{
    /**
     * Nombre del usuario (opcional)
     *
     * @var string|null
     */
    public ?string $name;

    /**
     * Email del usuario (opcional)
     *
     * @var string|null
     */
    public ?string $email;

    /**
     * Contraseña del usuario (opcional)
     *
     * @var string|null
     */
    public ?string $password;

    /**
     * Documento de identidad (opcional)
     *
     * @var string|null
     */
    public ?string $identity_document;

    /**
     * Crear una instancia desde un array de datos
     *
     * @param  array  $data  Array con los datos
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->name = $data['name'] ?? null;
        $dto->email = $data['email'] ?? null;
        $dto->password = $data['password'] ?? null;
        $dto->identity_document = $data['identity_document'] ?? null;

        return $dto;
    }

    /**
     * Convertir a array (excluyendo valores nulos para actualizaciones parciales)
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->email !== null) {
            $data['email'] = $this->email;
        }

        if ($this->password !== null) {
            $data['password'] = $this->password;
        }

        if ($this->identity_document !== null) {
            $data['identity_document'] = $this->identity_document;
        }

        return $data;
    }
}