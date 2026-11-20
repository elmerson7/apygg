<?php

namespace App\Support\SeedLoad;

use App\Models\User;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use App\Support\Sequences\SequenceNumber;
use App\Support\Tenancy\TenantRoles;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Genera datos masivos y realistas por lotes (docs/seed-load.md): empresas con miembros y roles, suscripciones
 * con facturas y pagos coherentes, archivos (solo metadatos) y notificaciones. Inserta en bloque con el
 * query builder (sin Eloquent, eventos ni observers) pero respetando las invariantes del dominio.
 *
 * - Reproducible: cada empresa sale de su propio `Mt19937(seed, índice)`, así que el resultado no depende del
 *   tamaño del lote ni de cuántas empresas haya antes.
 * - Numeración de facturas SIN HUECOS: por cada lote reserva un rango contiguo de la secuencia
 *   `billing.invoice.sequence` (bloqueo de fila + `next_value`, como `SequenceInterface`) y lo reparte en orden
 *   cronológico; con alcance `tenant`, una secuencia por empresa.
 * - Sin estado entre llamadas (Octane): todo vive en variables locales de `run()`.
 */
final class LoadDataGenerator
{
    public const EMAIL_DOMAIN = 'seed-load.test';

    public const SLUG_PREFIX = 'ld-';

    private const COMPANY_A = ['Aurora', 'Delta', 'Nova', 'Andes', 'Pacífico', 'Cobalto', 'Lumen', 'Atlas', 'Sol', 'Vértice', 'Brisa', 'Cumbre', 'Horizonte', 'Mosaico', 'Ámbar', 'Nube'];

    private const COMPANY_B = ['Labs', 'Logística', 'Soluciones', 'Studio', 'Foods', 'Médica', 'Tech', 'Consulting', 'Retail', 'Energía', 'Textil', 'Agro', 'Finanzas', 'Educación', 'Turismo', 'Motors'];

    private const FIRST_NAMES = ['Ana', 'Luis', 'María', 'Carlos', 'Lucía', 'Jorge', 'Sofía', 'Diego', 'Valeria', 'Andrés', 'Camila', 'Pedro', 'Elena', 'Miguel', 'Paula', 'Javier', 'Rosa', 'Mateo', 'Julia', 'Hugo'];

    private const LAST_NAMES = ['García', 'Rojas', 'Quispe', 'Torres', 'Vargas', 'Mendoza', 'Flores', 'Castro', 'Ramos', 'Silva', 'Paredes', 'Salazar', 'Núñez', 'Herrera', 'Vega', 'Ortiz'];

    private const TIMEZONES = ['America/Lima', 'America/Bogota', 'America/Mexico_City', 'Europe/Madrid', 'UTC'];

    private const FILE_KINDS = [
        ['pdf', 'application/pdf', 'document'],
        ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document'],
        ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'document'],
        ['csv', 'text/csv', 'document'],
        ['png', 'image/png', 'image'],
        ['jpg', 'image/jpeg', 'image'],
        ['zip', 'application/zip', 'archive'],
        ['mp4', 'video/mp4', 'video'],
        ['mp3', 'audio/mpeg', 'audio'],
    ];

    private const FILE_CATEGORIES = ['facturas', 'contratos', 'reportes', 'imagenes', 'importaciones'];

    /** @var Closure(int, int): void|null */
    private ?Closure $progress = null;

    /**
     * @param  Closure(int, int): void  $callback  recibe (empresas hechas, empresas totales)
     */
    public function onProgress(Closure $callback): self
    {
        $this->progress = $callback;

        return $this;
    }

    /**
     * Índice (1-based) de la próxima empresa de carga: continúa tras las ya generadas.
     */
    public function nextIndex(): int
    {
        $max = DB::table('tenants')
            ->whereRaw("slug ~ '^".self::SLUG_PREFIX."[0-9]+\$'")
            ->selectRaw("max(substring(slug from '[0-9]+\$')::bigint) as m")
            ->value('m');

        return (int) $max + 1;
    }

    /**
     * @return array{tenants: int, users: int, memberships: int, subscriptions: int, invoices: int, payments: int, files: int, notifications: int, first_index: int, last_index: int, credentials: list<array{tenant_id: int, slug: string, email: string, role: string}>}
     */
    public function run(LoadOptions $options): array
    {
        DB::disableQueryLog();

        $asOf = ($options->asOf ?? CarbonImmutable::now())->startOfSecond();
        $first = $this->nextIndex();
        $roleIds = $this->roleIds();
        $scenario = new BillingScenario($this->catalog(), array_map('intval', (array) config('billing.dunning.retry_days', [1, 3, 5])));
        $hash = (string) app('hash')->make($options->password);

        $totals = ['tenants' => 0, 'users' => 0, 'memberships' => 0, 'subscriptions' => 0, 'invoices' => 0, 'payments' => 0, 'files' => 0, 'notifications' => 0, 'first_index' => $first, 'last_index' => $first - 1, 'credentials' => []];

        for ($done = 0; $done < $options->tenants; $done += $options->batchSize) {
            $size = min($options->batchSize, $options->tenants - $done);
            $indexes = range($first + $done, $first + $done + $size - 1);

            $credentials = $totals['credentials'];
            $batch = DB::transaction(function () use ($options, $indexes, $asOf, $scenario, $roleIds, $hash, &$credentials): array {
                return $this->batch($options, $indexes, $asOf, $scenario, $roleIds, $hash, $credentials, $options->credentialsLimit);
            });
            $totals['credentials'] = $credentials;

            foreach ($batch as $key => $value) {
                $totals[$key] += $value;
            }
            $totals['last_index'] = (int) end($indexes);

            if ($this->progress !== null) {
                ($this->progress)($done + $size, $options->tenants);
            }
        }

        return $totals;
    }

    /**
     * @param  list<int>  $indexes
     * @param  array<string, int>  $roleIds
     * @param  list<array{tenant_id: int, slug: string, email: string, role: string}>  $credentials
     * @return array{tenants: int, users: int, memberships: int, subscriptions: int, invoices: int, payments: int, files: int, notifications: int}
     */
    private function batch(LoadOptions $options, array $indexes, CarbonImmutable $asOf, BillingScenario $scenario, array $roleIds, string $hash, array &$credentials, int $credentialsLimit): array
    {
        $n = count($indexes);
        $userCount = max(1, $options->usersPerTenant);
        $tenantIds = $this->reserveIds('tenants', $n);
        $userIds = $this->reserveIds('users', $n * $userCount);

        $roles = TenantRoles::names();
        $owner = TenantRoles::owner();
        $defaultRole = (string) config('multi-tenancy.onboarding.default_role', 'member');
        $adminRole = $this->adminRole($roles, $owner, $defaultRole);
        $teams = (bool) config('permission.teams');
        $teamKey = (string) config('permission.column_names.team_foreign_key', 'tenant_id');
        $userRoleId = $roleIds[(string) config('auth.default_role', 'user')] ?? null;
        $morph = (new User)->getMorphClass();
        $stamp = fn (CarbonInterface $d): string => $d->format('Y-m-d H:i:s');

        $tenantRows = $userRows = $memberRows = $roleRows = $accountRows = $subRows = $fileRows = $inboxRows = [];
        $invoicePlans = [];
        $subPlans = [];
        $members = [];   // tenantIndex => list<array{id:int, role:string}>
        $stats = ['tenants' => $n, 'users' => 0, 'memberships' => 0, 'subscriptions' => 0, 'invoices' => 0, 'payments' => 0, 'files' => 0, 'notifications' => 0];

        foreach ($indexes as $pos => $index) {
            $rng = new Randomizer(new Mt19937(($options->seed * 1_000_003 + $index) & 0x7FFFFFFF));
            $tenantId = $tenantIds[$pos];
            $slug = sprintf('%s%07d', self::SLUG_PREFIX, $index);
            $plan = $scenario->plan($rng, $asOf);
            $sub = $plan['subscription'];
            $created = $plan['created_at'];
            $locale = $rng->getInt(1, 100) <= 70 ? 'es' : 'en';

            $tenantRows[] = [
                'id' => $tenantId,
                'name' => self::COMPANY_A[$rng->getInt(0, 15)].' '.self::COMPANY_B[$rng->getInt(0, 15)],
                'slug' => $slug,
                'domain' => null,
                'settings' => json_encode(['seed_load' => true, 'seed' => $options->seed], JSON_THROW_ON_ERROR),
                'status' => $rng->getInt(1, 100) <= 3 ? 'suspended' : 'active',
                'plan' => $sub['plan_code'] ?? null,
                'trial_ends_at' => isset($sub['trial_ends_at']) ? $stamp($sub['trial_ends_at']) : null,
                'locale' => $locale,
                'timezone' => self::TIMEZONES[$rng->getInt(0, 4)],
                'currency' => $sub['currency'] ?? (string) config('billing.default_currency', 'USD'),
                'created_at' => $stamp($created),
                'updated_at' => $stamp($created),
            ];

            // Usuarios y membresías: 1 dueño, ~10 % administradores (si hay 3 o más) y el resto miembros.
            $admins = $userCount >= 3 ? (int) ceil($userCount * 0.1) : 0;
            $members[$index] = [];

            for ($k = 1; $k <= $userCount; $k++) {
                $userId = $userIds[$pos * $userCount + $k - 1];
                $role = $k === 1 ? $owner : ($k <= 1 + $admins ? $adminRole : $defaultRole);
                $joined = $created->addSeconds($rng->getInt(0, 7 * 86400));
                $joined = $joined->greaterThan($asOf) ? $asOf : $joined;
                $email = sprintf('u%d.%s@%s', $k, $slug, self::EMAIL_DOMAIN);

                $userRows[] = [
                    'id' => $userId,
                    'name' => self::FIRST_NAMES[$rng->getInt(0, 19)].' '.self::LAST_NAMES[$rng->getInt(0, 15)],
                    'email' => $email,
                    'email_verified_at' => $stamp($joined),
                    'password' => $hash,
                    'timezone' => self::TIMEZONES[$rng->getInt(0, 4)],
                    'locale' => $locale,
                    'created_at' => $stamp($joined),
                    'updated_at' => $stamp($joined),
                ];

                $suspended = $role === $defaultRole && $k > 1 + $admins && $rng->getInt(1, 100) <= 2;
                $memberRows[] = $this->membership($tenantId, $userId, $role, $suspended ? 'suspended' : 'active', $k === 1 ? null : $userIds[$pos * $userCount], $joined, $stamp);
                $members[$index][] = ['id' => $userId, 'role' => $role, 'joined' => $joined, 'active' => ! $suspended];
                $roleRows[] = $this->roleRow($userRoleId, $userId, $morph, $teams ? $teamKey : null, $teams ? $tenantId : null);
                $roleRows[] = $this->roleRow($roleIds[(string) config("multi-tenancy.onboarding.roles.{$role}.spatie_role")] ?? null, $userId, $morph, $teams ? $teamKey : null, $teams ? $tenantId : null);

                if (count($credentials) < $credentialsLimit * 2 && ($k === 1 || $k === $userCount) && ! $suspended) {
                    $credentials[] = ['tenant_id' => $tenantId, 'slug' => $slug, 'email' => $email, 'role' => $role];
                }

                // Avisos en la bandeja (solo a quien puede entrar).
                for ($j = 0; $j < $options->notificationsPerUser && ! $suspended; $j++) {
                    $at = $joined->addSeconds($rng->getInt(0, max(1, (int) $joined->diffInSeconds($asOf, true))));
                    $read = $rng->getInt(1, 100) <= 50;
                    $inboxRows[] = [
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'notification_id' => null,
                        'type' => $j % 2 === 0 ? 'system.notice' : 'billing.invoice-paid',
                        'title' => $j % 2 === 0 ? 'Novedades de la plataforma' : 'Factura pagada',
                        'body' => 'Mensaje de ejemplo generado por seed:load.',
                        'data' => json_encode(['seed_load' => true], JSON_THROW_ON_ERROR),
                        'read_at' => $read ? $stamp($at->addMinutes(5)->greaterThan($asOf) ? $asOf : $at->addMinutes(5)) : null,
                        'created_at' => $stamp($at),
                        'updated_at' => $stamp($at),
                    ];
                }
            }

            // Archivos: solo metadatos (sin binarios), de miembros de ESTA empresa.
            $uploaders = array_values(array_filter($members[$index], fn (array $m) => $m['active']));
            for ($f = 1; $f <= $options->filesPerTenant; $f++) {
                [$ext, $mime, $type] = self::FILE_KINDS[$rng->getInt(0, count(self::FILE_KINDS) - 1)];
                $uploader = $uploaders[$rng->getInt(0, count($uploaders) - 1)] ?? null;
                $at = $created->addSeconds($rng->getInt(0, max(1, (int) $created->diffInSeconds($asOf, true))));
                $name = sprintf('archivo-%04d.%s', $f, $ext);
                $fileRows[] = [
                    'tenant_id' => $tenantId,
                    'user_id' => $uploader['id'] ?? null,
                    'name' => $name,
                    'filename' => $name,
                    'path' => sprintf('seed-load/%d/%s', $tenantId, $name),
                    'url' => null,
                    'disk' => 'local',
                    'mime_type' => $mime,
                    'extension' => $ext,
                    'size' => $rng->getInt(2_048, 25_000_000),
                    'type' => $type,
                    'category' => self::FILE_CATEGORIES[$rng->getInt(0, 4)],
                    'description' => null,
                    'metadata' => json_encode(['seed_load' => true], JSON_THROW_ON_ERROR),
                    'is_public' => false,
                    'created_at' => $stamp($at),
                    'updated_at' => $stamp($at),
                ];
            }

            if ($sub !== null) {
                $subPlans[$index] = ['tenant_id' => $tenantId, 'plan' => $sub];
                if ($plan['payment_method']) {
                    $accountRows[] = [
                        'tenant_id' => $tenantId,
                        'gateway' => 'fake',
                        'gateway_customer_id' => 'fake_cus_seed_'.$tenantId,
                        'payment_method_id' => 'fake_pm_seed_'.$tenantId,
                        'payment_method_brand' => $rng->getInt(0, 1) === 0 ? 'visa' : 'mastercard',
                        'payment_method_last4' => sprintf('%04d', $rng->getInt(0, 9999)),
                        'billing_email' => sprintf('u1.%s@%s', $slug, self::EMAIL_DOMAIN),
                        'created_at' => $stamp($created),
                        'updated_at' => $stamp($created),
                    ];
                }
                foreach ($plan['invoices'] as $inv) {
                    $invoicePlans[] = ['index' => $index, 'tenant_id' => $tenantId, 'inv' => $inv];
                }
            }
        }

        // Membresías cruzadas: ~3 % de los miembros rasos también pertenecen a OTRA empresa del lote.
        if ($n >= 2) {
            $rng = new Randomizer(new Mt19937(($options->seed * 1_000_003 + $indexes[0] + 7) & 0x7FFFFFFF));
            foreach ($indexes as $pos => $index) {
                foreach ($members[$index] as $m) {
                    if ($m['role'] !== $defaultRole || ! $m['active'] || $rng->getInt(1, 100) > 3) {
                        continue;
                    }
                    $other = ($pos + $rng->getInt(1, $n - 1)) % $n;
                    $memberRows[] = $this->membership($tenantIds[$other], $m['id'], $defaultRole, 'active', null, $m['joined'], $stamp);
                    $roleRows[] = $this->roleRow($roleIds[(string) config("multi-tenancy.onboarding.roles.{$defaultRole}.spatie_role")] ?? null, $m['id'], $morph, $teams ? $teamKey : null, $teams ? $tenantIds[$other] : null);
                }
            }
        }

        // Suscripciones (ids reservados: las facturas las referencian).
        $subIds = $this->reserveIds('subscriptions', count($subPlans));
        $subIdByIndex = [];
        $i = 0;
        foreach ($subPlans as $index => $entry) {
            $s = $entry['plan'];
            $id = $subIds[$i++];
            $subIdByIndex[$index] = $id;
            $subRows[] = [
                'id' => $id,
                'tenant_id' => $entry['tenant_id'],
                'plan_id' => $s['plan_id'],
                'plan_price_id' => $s['plan_price_id'],
                'plan_code' => $s['plan_code'],
                'status' => $s['status'],
                'interval' => $s['interval'],
                'currency' => $s['currency'],
                'unit_amount_minor' => $s['unit_amount_minor'],
                'trial_ends_at' => $s['trial_ends_at'] !== null ? $stamp($s['trial_ends_at']) : null,
                'billing_anchor_at' => $stamp($s['billing_anchor_at']),
                'current_period_start' => $stamp($s['current_period_start']),
                'current_period_end' => $stamp($s['current_period_end']),
                'cancel_at_period_end' => $s['cancel_at_period_end'],
                'canceled_at' => $s['canceled_at'] !== null ? $stamp($s['canceled_at']) : null,
                'ended_at' => $s['ended_at'] !== null ? $stamp($s['ended_at']) : null,
                'renewal_attempts' => $s['renewal_attempts'],
                'next_retry_at' => $s['next_retry_at'] !== null ? $stamp($s['next_retry_at']) : null,
                'last_failure_code' => $s['last_failure_code'],
                'created_at' => $stamp($s['created_at']),
                'updated_at' => $stamp($s['created_at']),
            ];
        }

        $this->insert('tenants', $tenantRows);
        // Después de las empresas: con serie por tenant, `sequences.tenant_id` es una FK.
        [$invoiceRows, $lineRows, $paymentRows] = $this->invoices($invoicePlans, $subIdByIndex, $subPlans, $stamp);
        $this->insert('users', $userRows);
        $this->insert('tenant_user', $memberRows);
        $this->insert('model_has_roles', $this->uniqueRoleRows(array_values(array_filter($roleRows, fn (array $r) => $r['role_id'] !== null)), $teams ? $teamKey : null));
        $this->insert('billing_accounts', $accountRows);
        $this->insert('subscriptions', $subRows);
        $this->insert('invoices', $invoiceRows);
        $this->insert('invoice_lines', $lineRows);
        $this->insert('payments', $paymentRows);
        $this->insert('files', $fileRows);
        $this->insert('inbox_notifications', $inboxRows);

        $stats['users'] = count($userRows);
        $stats['memberships'] = count($memberRows);
        $stats['subscriptions'] = count($subRows);
        $stats['invoices'] = count($invoiceRows);
        $stats['payments'] = count($paymentRows);
        $stats['files'] = count($fileRows);
        $stats['notifications'] = count($inboxRows);

        return $stats;
    }

    /**
     * Facturas (con numeración sin huecos), líneas y pagos de un lote.
     *
     * @param  list<array{index: int, tenant_id: int, inv: array<string, mixed>}>  $plans
     * @param  array<int, int>  $subIdByIndex
     * @param  array<int, array{tenant_id: int, plan: array<string, mixed>}>  $subPlans
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function invoices(array $plans, array $subIdByIndex, array $subPlans, Closure $stamp): array
    {
        if ($plans === []) {
            return [[], [], []];
        }

        $invoiceIds = $this->reserveIds('invoices', count($plans));
        $rate = (string) config('billing.tax.rate', '0');
        $label = (string) config('billing.tax.label', '');
        $scope = config('billing.invoice.sequence_scope') === 'tenant' ? 'tenant' : 'global';
        $dueDays = (int) config('billing.invoice.due_days', 0);
        $name = (string) config('billing.invoice.sequence', 'billing_invoice');

        // Orden cronológico (y por empresa) para asignar los números.
        $order = array_keys($plans);
        usort($order, function (int $a, int $b) use ($plans): int {
            return [$plans[$a]['inv']['issued_at'], $plans[$a]['tenant_id'], $a] <=> [$plans[$b]['inv']['issued_at'], $plans[$b]['tenant_id'], $b];
        });

        $keyOf = fn (int $p): string => $this->sequenceKey($scope === 'tenant' ? $plans[$p]['tenant_id'] : null, $this->period($name, $plans[$p]['inv']['issued_at']));
        $needs = [];
        foreach ($order as $p) {
            $needs[$keyOf($p)] = ($needs[$keyOf($p)] ?? 0) + 1;
        }
        $reserved = $this->reserveSequences($name, $needs);

        $numbers = [];
        foreach ($order as $p) {
            $r = &$reserved[$keyOf($p)];
            $numbers[$p] = (string) SequenceNumber::format($r['next']++, $r['prefix'], $r['padding'], $r['period']);
            unset($r);
        }

        $invoiceRows = $lineRows = $paymentRows = [];

        foreach ($plans as $p => $entry) {
            $inv = $entry['inv'];
            $sub = $subPlans[$entry['index']]['plan'];
            $id = $invoiceIds[$p];
            $currency = (string) $sub['currency'];
            $subtotal = Money::of((int) $sub['unit_amount_minor'], $currency);
            $tax = $subtotal->isPositive() && bccomp($rate, '0', 6) > 0 ? $subtotal->percentage($rate, Rounding::HalfUp) : Money::zero($currency);
            $total = $subtotal->add($tax);
            $issued = $inv['issued_at'];

            $invoiceRows[] = [
                'id' => $id,
                'tenant_id' => $entry['tenant_id'],
                'subscription_id' => $subIdByIndex[$entry['index']],
                'plan_price_id' => $sub['plan_price_id'],
                'number' => $numbers[$p],
                'number_scope' => $scope,
                'status' => $inv['status'],
                'billing_reason' => $inv['reason'],
                'currency' => $currency,
                'subtotal_minor' => $subtotal->amountMinor,
                'tax_minor' => $tax->amountMinor,
                'total_minor' => $total->amountMinor,
                'tax_rate' => $rate,
                'tax_label' => $label,
                'period_start' => $stamp($inv['period_start']),
                'period_end' => $stamp($inv['period_end']),
                'issued_at' => $stamp($issued),
                'due_at' => $stamp($issued->addDays($dueDays)),
                'paid_at' => $inv['paid_at'] !== null ? $stamp($inv['paid_at']) : null,
                'created_at' => $stamp($issued),
                'updated_at' => $stamp($inv['paid_at'] ?? $issued),
            ];

            $lineRows[] = [
                'invoice_id' => $id,
                'type' => 'subscription',
                'description' => $sub['plan_name'].' ('.($sub['interval'] === 'year' ? 'anual' : 'mensual').')',
                'quantity' => 1,
                'unit_amount_minor' => $subtotal->amountMinor,
                'amount_minor' => $subtotal->amountMinor,
                'currency' => $currency,
                'period_start' => $stamp($inv['period_start']),
                'period_end' => $stamp($inv['period_end']),
                'created_at' => $stamp($issued),
                'updated_at' => $stamp($issued),
            ];

            foreach ($inv['attempts'] as $a) {
                $ok = $a['status'] === 'succeeded';
                $paymentRows[] = [
                    'tenant_id' => $entry['tenant_id'],
                    'invoice_id' => $id,
                    'attempt' => $a['attempt'],
                    'method' => 'gateway',
                    'gateway' => 'fake',
                    'status' => $a['status'],
                    'amount_minor' => $total->amountMinor,
                    'currency' => $currency,
                    'idempotency_key' => "inv-{$id}-a{$a['attempt']}",
                    'gateway_payment_id' => $ok ? "fake_pay_seed_{$id}_{$a['attempt']}" : null,
                    'failure_code' => $a['failure_code'],
                    'failure_message' => $ok ? null : 'Your card was declined.',
                    'tries' => 1,
                    'processed_at' => $stamp($a['at']),
                    'created_at' => $stamp($a['at']),
                    'updated_at' => $stamp($a['at']),
                ];
            }
        }

        return [$invoiceRows, $lineRows, $paymentRows];
    }

    /**
     * Reserva rangos contiguos de la secuencia (bloqueo de fila, como `SequenceInterface`). Orden de claves
     * fijo para no provocar interbloqueos con otro lote en paralelo.
     *
     * @param  array<string, int>  $needs  clave "tenant|periodo" => cuántos números
     * @return array<string, array{next: int, prefix: ?string, padding: int, period: string}>
     */
    private function reserveSequences(string $name, array $needs): array
    {
        ksort($needs);
        $def = array_merge((array) config('sequences.defaults', []), (array) config("sequences.definitions.{$name}", []));
        $reserved = [];

        foreach ($needs as $key => $count) {
            [$tenant, $period] = explode('|', $key, 2);
            $tenantId = $tenant === '' ? null : (int) $tenant;

            $query = fn () => DB::table('sequences')->where('name', $name)->where('period', $period)
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId));

            $row = $query()->lockForUpdate()->first();

            if ($row === null) {
                DB::table('sequences')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'period' => $period,
                    'prefix' => isset($def['prefix']) && $def['prefix'] !== '' ? (string) $def['prefix'] : null,
                    'padding' => max(0, min(20, (int) ($def['padding'] ?? 0))),
                    'next_value' => max(1, (int) ($def['start'] ?? 1)),
                    'max_value' => isset($def['max']) ? (int) $def['max'] : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = $query()->lockForUpdate()->first() ?? throw new RuntimeException("No se pudo crear la secuencia [{$name}].");
            }

            $start = (int) $row->next_value;

            if ($row->max_value !== null && $start + $count - 1 > (int) $row->max_value) {
                throw new RuntimeException("La secuencia [{$name}] no tiene números para {$count} facturas más (máximo {$row->max_value}).");
            }

            $query()->update(['next_value' => $start + $count, 'updated_at' => now()]);
            $reserved[$key] = ['next' => $start, 'prefix' => $row->prefix, 'padding' => (int) $row->padding, 'period' => (string) $row->period];
        }

        return $reserved;
    }

    private function sequenceKey(?int $tenantId, string $period): string
    {
        return ($tenantId ?? '').'|'.$period;
    }

    private function period(string $name, CarbonInterface $issuedAt): string
    {
        $reset = (string) (config("sequences.definitions.{$name}.reset") ?? config('sequences.defaults.reset', 'none'));

        return $reset === 'yearly' ? $issuedAt->format('Y') : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function membership(int $tenantId, int $userId, string $role, string $status, ?int $invitedBy, CarbonInterface $joined, Closure $stamp): array
    {
        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => $status,
            'role' => $role,
            'invited_by' => $invitedBy,
            'joined_at' => $stamp($joined),
            'created_at' => $stamp($joined),
            'updated_at' => $stamp($joined),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleRow(?int $roleId, int $userId, string $morph, ?string $teamKey, ?int $tenantId): array
    {
        $row = ['role_id' => $roleId, 'model_type' => $morph, 'model_id' => $userId];

        if ($teamKey !== null) {
            $row[$teamKey] = $tenantId;
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function uniqueRoleRows(array $rows, ?string $teamKey): array
    {
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $key = $row['role_id'].'|'.$row['model_id'].'|'.($teamKey !== null ? $row[$teamKey] : '');
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Rol del tenant justo debajo del dueño (administrador); si no existe, el predeterminado.
     *
     * @param  list<string>  $roles
     */
    private function adminRole(array $roles, string $owner, string $default): string
    {
        $candidates = array_values(array_filter($roles, fn (string $r) => $r !== $owner && $r !== $default));
        usort($candidates, fn (string $a, string $b) => TenantRoles::rank($b) <=> TenantRoles::rank($a));

        return $candidates[0] ?? $default;
    }

    /**
     * @return array<string, int>
     */
    private function roleIds(): array
    {
        /** @var array<string, int> $ids */
        $ids = DB::table('roles')->where('guard_name', 'api')->pluck('id', 'name')->map(fn ($v) => (int) $v)->all();

        foreach (TenantRoles::names() as $role) {
            $spatie = (string) config("multi-tenancy.onboarding.roles.{$role}.spatie_role");
            if (! isset($ids[$spatie])) {
                throw new RuntimeException("Falta el rol [{$spatie}]: ejecuta `php artisan db:seed --class=RoleSeeder`.");
            }
        }

        return $ids;
    }

    /**
     * Catálogo de planes y precios activos de la BD (`billing:sync-plans`).
     *
     * @return array<string, array{plan_id: int, name: string, trial_days: int, public: bool, prices: array<string, array<string, array{id: int, amount: int}>>}>
     */
    private function catalog(): array
    {
        $catalog = [];

        $plans = DB::table('plans')->where('is_active', true)->whereNull('deleted_at')->orderBy('sort_order')->get();
        foreach ($plans as $plan) {
            $catalog[(string) $plan->code] = ['plan_id' => (int) $plan->id, 'name' => (string) $plan->name, 'trial_days' => (int) $plan->trial_days, 'public' => (bool) $plan->is_public, 'prices' => []];
        }

        $prices = DB::table('plan_prices')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->get();
        $codeById = $plans->pluck('code', 'id');
        foreach ($prices as $price) {
            $code = $codeById[$price->plan_id] ?? null;
            if ($code !== null) {
                $catalog[(string) $code]['prices'][(string) $price->currency][(string) $price->interval] = ['id' => (int) $price->id, 'amount' => (int) $price->unit_amount_minor];
            }
        }

        if ($catalog === []) {
            throw new RuntimeException('No hay planes: ejecuta `php artisan billing:sync-plans`.');
        }

        return $catalog;
    }

    /**
     * Reserva `$count` ids de la secuencia de la tabla de una vez (`nextval` en serie).
     *
     * @return list<int>
     */
    private function reserveIds(string $table, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $rows = DB::select("select nextval(pg_get_serial_sequence('{$table}', 'id')) as id from generate_series(1, {$count})");

        return array_map(fn (object $r): int => (int) $r->id, $rows);
    }

    /**
     * Inserción en bloque troceada para no pasar de los 65 535 parámetros de PostgreSQL.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $size = max(1, intdiv(60_000, count($rows[0])));

        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
