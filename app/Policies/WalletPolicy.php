<?php

namespace App\Policies;

use App\Models\User;
use App\Services\LogService;

/**
 * WalletPolicy
 *
 * Policy para autorización de acciones sobre la wallet.
 * Solo el dueño puede ver y operar su wallet.
 */
class WalletPolicy
{
    public function view(User $user, \App\Models\Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id;
    }

    public function credit(User $user, \App\Models\Wallet $wallet): bool
    {
        $allowed = $user->hasPermission('wallet.credit') || $user->id === $wallet->user_id;

        if ($allowed) {
            LogService::info('Operación de crédito en wallet autorizada', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
            ], 'security');
        }

        return $allowed;
    }

    public function debit(User $user, \App\Models\Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id;
    }
}
