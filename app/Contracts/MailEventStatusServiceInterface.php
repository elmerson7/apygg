<?php

namespace App\Contracts;

use App\Models\Mail\MailLog;

/**
 * MailEventStatusServiceInterface
 *
 * Contrato del estado de entrega a partir de eventos del proveedor.
 */
interface MailEventStatusServiceInterface
{
    /**
     * Estado correspondiente a un tipo de evento, o null si es desconocido.
     */
    public function mapType(string $type): ?string;

    /**
     * Estado que le corresponde al log según sus eventos.
     */
    public function resolveStatusForLog(MailLog $log): ?string;

    /**
     * Sincronizar el estado del log con sus eventos. Devuelve true si cambió.
     */
    public function syncStatus(MailLog $log): bool;
}
