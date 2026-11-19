<?php

namespace App\Contracts;

use App\Models\Mail\MailAccount;

/**
 * MailServiceInterface
 *
 * Contrato del envío de correo por el camino estándar de Laravel (cuentas, plantillas, pausa y límites).
 */
interface MailServiceInterface
{
    /**
     * Enviar un correo (encolado por defecto).
     *
     * Con `queue: false` devuelve si el envío se realizó; encolado devuelve
     * true al aceptarlo (la pausa y el límite se aplican al enviar de verdad).
     *
     * @param  string|array{email: string, name?: string}|array<int, string|array{email: string, name?: string}>  $to
     * @param  array<string, mixed>  $data  Datos para la vista
     * @param  string|null  $templateKey  Clave de `mail_template_configs`
     * @param  MailAccount|null  $account  Fuerza esta cuenta de envío
     * @param  array<int, array{content: string, filename: string}>  $attachments
     */
    public function send(string|array $to,
        string $subject,
        string $view,
        array $data = [],
        ?string $templateKey = null,
        bool $queue = true,
        ?MailAccount $account = null,
        array $attachments = [],
        ?int $delaySeconds = null, ): bool;
}
