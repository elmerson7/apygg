<?php

namespace App\Console\Commands;

use App\Contracts\PaymentReconcilerInterface;
use Illuminate\Console\Command;

/**
 * Conciliación con la pasarela (docs/billing.md §Conciliación): consulta por clave/id los cobros inciertos ANTES de que
 * `billing:renew` reenvíe nada, cancela los 3DS vencidos y cuadra los cobros recientes de la pasarela con los locales
 * (un cobro sin reflejo queda en `payment_discrepancies` con alerta). Idempotente y seguro con varias instancias.
 *
 * Uso: php artisan payments:reconcile
 */
class PaymentsReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Cuadrar los cobros locales con la pasarela (inciertos, 3DS vencidos y cobros sin reflejo)';

    public function handle(PaymentReconcilerInterface $reconciler): int
    {
        $counts = $reconciler->reconcile();

        $this->info('Conciliación: '.($counts === [] ? 'nada que hacer' : implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts))));

        return ($counts['stale_error'] ?? 0) + ($counts['action_error'] ?? 0) + ($counts['list_failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
