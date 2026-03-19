<?php

namespace App\DTOs;

/**
 * CreateWebhookDTO
 *
 * Data Transfer Object para crear nuevos webhooks.
 */
class CreateWebhookDTO
{
    /**
     * Nombre del webhook
     *
     * @var string
     */
    public string $name;

    /**
     * URL del webhook
     *
     * @var string
     */
    public string $url;

    /**
     * Eventos a los que se suscribe el webhook (opcional)
     *
     * @var array|null
     */
    public ?array $events;

    /**
     * Estado del webhook (opcional)
     *
     * @var string|null
     */
    public ?string $status;

    /**
     * Timeout en segundos (opcional)
     *
     * @var int|null
     */
    public ?int $timeout;

    /**
     * Máximo de reintentos (opcional)
     *
     * @var int|null
     */
    public ?int $max_retries;

    /**
     * Secret para firmar las peticiones (opcional)
     *
     * @var string|null
     */
    public ?string $secret;

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
        $dto->url = $data['url'] ?? '';
        $dto->events = $data['events'] ?? null;
        $dto->status = $data['status'] ?? null;
        $dto->timeout = $data['timeout'] ?? null;
        $dto->max_retries = $data['max_retries'] ?? null;
        $dto->secret = $data['secret'] ?? null;

        return $dto;
    }

    /**
     * Convertir a array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events,
            'status' => $this->status,
            'timeout' => $this->timeout,
            'max_retries' => $this->max_retries,
            'secret' => $this->secret,
        ];
    }
}