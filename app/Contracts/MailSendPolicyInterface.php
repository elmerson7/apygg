<?php

namespace App\Contracts;

/**
 * MailSendPolicyInterface
 *
 * Contrato de la política de envío (pausa, plantillas desactivadas, límite, destinatarios vetados).
 */
interface MailSendPolicyInterface
{
    /**
     * Motivo por el que NO se puede enviar, o null si se puede.
     */
    public function denialReason(?string $templateKey): ?string;

    /**
     * Destinatarios que no deben recibir este correo: dominio reservado o dirección suprimida.
     * Devuelve `dirección => motivo` solo de los descartados.
     *
     * @param  list<string>  $addresses
     * @return array<string, string>
     */
    public function deniedRecipients(array $addresses, ?string $templateKey): array;
}
