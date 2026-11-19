<?php

namespace App\Contracts;

/**
 * MailSendPolicyInterface
 *
 * Contrato de la política de envío (pausa y plantillas desactivadas).
 */
interface MailSendPolicyInterface
{
    /**
     * Motivo por el que NO se puede enviar, o null si se puede.
     */
    public function denialReason(?string $templateKey): ?string;
}
