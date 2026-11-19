<?php

namespace App\Services\Mail;

use App\Contracts\MailAccountServiceInterface;
use App\Models\Mail\MailAccount;
use Illuminate\Support\Facades\DB;

/**
 * MailAccountService
 *
 * Alta y edición de cuentas de envío.
 *
 * Existe para que la regla "solo una cuenta por defecto" viva en un único
 * sitio: si se aplicara en el controlador, cualquier otra vía de creación
 * (un seeder, un comando, un importador) podría dejar dos cuentas marcadas
 * como predeterminada y el envío elegiría una al azar.
 */
class MailAccountService implements MailAccountServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): MailAccount
    {
        return DB::transaction(function () use ($data): MailAccount {
            $account = MailAccount::create($data);

            if ($account->is_default) {
                $this->clearOtherDefaults($account);
            }

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MailAccount $account, array $data): MailAccount
    {
        return DB::transaction(function () use ($account, $data): MailAccount {
            $account->update($data);

            if ($account->is_default) {
                $this->clearOtherDefaults($account);
            }

            return $account;
        });
    }

    /**
     * Solo puede haber una cuenta marcada como predeterminada.
     */
    protected function clearOtherDefaults(MailAccount $account): void
    {
        MailAccount::query()
            ->where('id', '!=', $account->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
