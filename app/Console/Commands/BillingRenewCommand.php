<?php

namespace App\Console\Commands;

use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Contracts\RenewalServiceInterface;
use App\Enums\TenantStatus;
use App\Exceptions\BillingException;
use App\Models\Tenant;
use App\Services\LogService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ciclo de cobro (docs/billing.md §Renovación): emite y cobra las renovaciones vencidas, reintenta los cobros fallidos
 * según `billing.dunning.retry_days`, caduca las altas sin pagar y retoma cobros interrumpidos.
 * Idempotente y seguro con varias instancias a la vez (bloqueo por suscripción + índice único por periodo).
 *
 * Uso: php artisan billing:renew [--subscription=ID]
 */
class BillingRenewCommand extends Command
{
    protected $signature = 'billing:renew {--subscription= : Procesar solo esta suscripción} {--limit= : Máximo de suscripciones por ejecución (por defecto billing.renew_batch)}';

    protected $description = 'Emitir y cobrar las renovaciones de suscripciones vencidas y los reintentos de cobro';

    public function handle(RenewalServiceInterface $renewals, InvoiceServiceInterface $invoices, PaymentProcessorInterface $payments, TenantContext $tenants): int
    {
        try {
            $invoices->assertNumberingScope();
        } catch (BillingException $e) {
            $this->error($e->getMessage());
            LogService::error('billing:renew no se ejecuta: '.$e->getMessage(), [], 'security');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : (int) config('billing.renew_batch', 200);

        if ($this->option('subscription') !== null) {
            $id = (int) $this->option('subscription');
            $tenantId = TenantQuery::withoutTenant(fn () => DB::table('subscriptions')->where('id', $id)->value('tenant_id'), 'billing:renew de una suscripción');
            $due = $tenantId === null ? [] : [['id' => $id, 'tenant_id' => (int) $tenantId]];
        } else {
            $due = $renewals->due($limit);
        }

        $counts = [];
        $errors = 0;

        foreach ($due as $row) {
            try {
                $tenant = Tenant::query()->find($row['tenant_id']);

                if (! $tenant instanceof Tenant) {
                    continue;
                }

                // Cada suscripción se procesa DENTRO de su tenant (aislamiento fail-closed), no con un salto global.
                if ($tenant->status !== TenantStatus::Active && ! config('billing.renew_inactive_tenants', false)) {
                    $counts['skipped_inactive_tenant'] = ($counts['skipped_inactive_tenant'] ?? 0) + 1;
                    LogService::info('billing:renew omite una suscripción de un tenant no activo', ['tenant_id' => $tenant->id, 'tenant_status' => $tenant->status->value, 'subscription_id' => $row['id']], 'security');

                    continue;
                }

                $outcome = $tenants->run($tenant, fn () => $renewals->renew($row['id']));
                $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
            } catch (Throwable $e) {
                $errors++;
                LogService::error('billing:renew falló para una suscripción', ['subscription_id' => $row['id'], 'error' => $e::class.': '.$e->getMessage()], 'security');
            }
        }

        // Cobros hechos cuya factura quedó anulada: se devuelven (también de tenants no activos).
        foreach ($payments->orphanedPayments($limit) as $orphan) {
            try {
                $tenant = Tenant::query()->find($orphan['tenant_id']);
                if ($tenant instanceof Tenant && $tenants->run($tenant, fn () => $payments->refund($orphan['id']))) {
                    $counts['refunded'] = ($counts['refunded'] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                $errors++;
                LogService::error('billing:renew: reembolso fallido', ['payment_id' => $orphan['id'], 'error' => $e::class.': '.$e->getMessage()], 'security');
            }
        }

        ksort($counts);
        $this->info('Suscripciones procesadas: '.count($due).' ('.($counts === [] ? 'nada que hacer' : implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts))).')');

        if ($errors > 0) {
            $this->error("{$errors} suscripciones fallaron (ver el log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
