<?php

namespace App\Contracts;

use App\Models\Mail\MailAccount;

/**
 * MailAccountServiceInterface
 *
 * Contrato de gestión de cuentas de correo.
 */
interface MailAccountServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MailAccount;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MailAccount $account, array $data): MailAccount;
}
