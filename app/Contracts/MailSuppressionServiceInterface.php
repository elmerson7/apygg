<?php

namespace App\Contracts;

use App\Models\Mail\MailSuppression;

/**
 * Lista de supresión de correo: direcciones a las que no se envía (rebote duro, queja, manual).
 */
interface MailSuppressionServiceInterface
{
    /** Minúsculas y sin espacios: la forma con la que se guarda y se compara. */
    public function normalize(string $email): string;

    /**
     * Suprime una dirección. Idempotente: una queja sustituye a un rebote; lo manual no se degrada.
     *
     * @param  array<string, mixed>  $details
     */
    public function suppress(string $email, string $reason, string $source, array $details = []): MailSuppression;

    /**
     * Procesa un evento de entrega del proveedor y suprime lo que corresponda (rebote permanente o queja).
     * Devuelve las direcciones suprimidas.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function suppressFromProviderEvent(array $payload, string $source): array;

    /**
     * Quita una dirección de la lista. Devuelve true si estaba.
     */
    public function release(string $email): bool;

    /**
     * Supresiones vigentes entre las direcciones dadas, indexadas por dirección normalizada.
     *
     * @param  list<string>  $emails
     * @return array<string, MailSuppression>
     */
    public function lookup(array $emails): array;
}
