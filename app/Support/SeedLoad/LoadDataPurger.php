<?php

namespace App\Support\SeedLoad;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Borra todo lo que generó `LoadDataGenerator` (empresas `ld-NNNNNNN` marcadas con `settings.seed_load` y
 * usuarios `@seed-load.test`) y deja la secuencia global de facturas sin huecos: el siguiente número es el
 * máximo de las facturas que sobreviven + 1 (o la fila desaparece si no queda ninguna).
 */
final class LoadDataPurger
{
    private const CHUNK = 200;

    /**
     * @return array{tenants: int, users: int, invoices: int}
     */
    public function run(): array
    {
        DB::disableQueryLog();

        $tenants = 0;
        $invoices = 0;

        while (true) {
            $ids = DB::table('tenants')
                ->whereRaw("slug ~ '^".LoadDataGenerator::SLUG_PREFIX."[0-9]+\$'")
                ->whereRaw("settings->>'seed_load' = 'true'")
                ->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($ids, &$tenants, &$invoices): void {
                $invoiceIds = DB::table('invoices')->whereIn('tenant_id', $ids)->select('id');

                DB::table('payments')->whereIn('tenant_id', $ids)->delete();
                DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
                $invoices += DB::table('invoices')->whereIn('tenant_id', $ids)->delete();
                DB::table('subscriptions')->whereIn('tenant_id', $ids)->delete();
                DB::table('files')->whereIn('tenant_id', $ids)->delete();
                DB::table('inbox_notifications')->whereIn('tenant_id', $ids)->delete();
                // tenant_user, sequences y billing_accounts caen en cascada con el tenant.
                $tenants += DB::table('tenants')->whereIn('id', $ids)->delete();
            });
        }

        $users = $this->purgeUsers();
        $this->fixGlobalSequence();

        return ['tenants' => $tenants, 'users' => $users, 'invoices' => $invoices];
    }

    private function purgeUsers(): int
    {
        $morph = (new User)->getMorphClass();
        $deleted = 0;

        while (true) {
            $ids = DB::table('users')->where('email', 'like', '%@'.LoadDataGenerator::EMAIL_DOMAIN)->orderBy('id')->limit(2000)->pluck('id')->all();

            if ($ids === []) {
                return $deleted;
            }

            DB::transaction(function () use ($ids, $morph, &$deleted): void {
                DB::table('model_has_roles')->where('model_type', $morph)->whereIn('model_id', $ids)->delete();
                DB::table('model_has_permissions')->where('model_type', $morph)->whereIn('model_id', $ids)->delete();
                $deleted += DB::table('users')->whereIn('id', $ids)->delete();
            });
        }
    }

    private function fixGlobalSequence(): void
    {
        $name = (string) config('billing.invoice.sequence', 'billing_invoice');

        DB::transaction(function () use ($name): void {
            $rows = DB::table('sequences')->whereNull('tenant_id')->where('name', $name)->lockForUpdate()->get();

            foreach ($rows as $row) {
                $query = DB::table('invoices')->where('number_scope', 'global')
                    ->when($row->period !== '', fn ($q) => $q->whereRaw('extract(year from issued_at)::int = ?', [(int) $row->period]));

                $max = $query->selectRaw("max((substring(number from '[0-9]+\$'))::bigint) as m")->value('m');

                if ($max === null) {
                    DB::table('sequences')->where('id', $row->id)->delete();
                } elseif ((int) $max + 1 < (int) $row->next_value) {
                    DB::table('sequences')->where('id', $row->id)->update(['next_value' => (int) $max + 1, 'updated_at' => now()]);
                }
            }
        });
    }
}
