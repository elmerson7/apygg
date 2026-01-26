<?php

namespace App\Jobs;

use App\Services\NotificationService;

/**
 * SendNotificationJob
 *
 * Job genérico para enviar notificaciones multi-canal.
 */
class SendNotificationJob extends Job
{
    /**
     * Canales a usar para la notificación
     */
    protected array $channels;

    /**
     * Usuario o identificador del destinatario
     */
    protected mixed $notifiable;

    /**
     * Título de la notificación
     */
    protected string $title;

    /**
     * Mensaje de la notificación
     */
    protected string $message;

    /**
     * Datos adicionales para la notificación
     */
    protected array $data;

    /**
     * Crear una nueva instancia del job
     */
    public function __construct(
        array $channels,
        mixed $notifiable,
        string $title,
        string $message,
        array $data = []
    ) {
        parent::__construct();
        $this->channels = $channels;
        $this->notifiable = $notifiable;
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * Ejecutar el job
     */
    protected function process(): void
    {
        $results = NotificationService::sendMultiChannel(
            $this->channels,
            $this->notifiable,
            $this->title,
            $this->message,
            $this->data,
            false // Ya estamos en cola, no necesitamos otra cola
        );

        $successCount = count(array_filter($results));
        $totalCount = count($results);

        $this->log('info', "Notificación enviada a través de {$successCount}/{$totalCount} canales", [
            'channels' => $this->channels,
            'title' => $this->title,
            'results' => $results,
            'notifiable_id' => is_object($this->notifiable) ? ($this->notifiable->id ?? null) : null,
        ]);

        // Si ningún canal tuvo éxito, lanzar excepción
        if ($successCount === 0) {
            throw new \RuntimeException('No se pudo enviar la notificación a través de ningún canal');
        }
    }
}
