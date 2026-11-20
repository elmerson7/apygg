<?php

namespace App\Support\SeedLoad;

use App\Enums\BillingInterval;
use App\Support\Billing\PeriodCalculator;
use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * Planifica, de forma pura y determinista, la vida de facturación de un tenant de prueba: qué suscripción
 * tiene, qué periodos ha cobrado y qué intentos de pago hay detrás de cada factura. Reproduce lo que
 * hacen `SubscriptionService` y `RenewalService` (periodos con `PeriodCalculator`, una factura por periodo,
 * `past_due` con el periodo viejo y la factura nueva abierta...) sin tocar la BD.
 *
 * Catálogo: `code => [plan_id, name, trial_days, public, prices: currency => interval => [id, amount]]`.
 *
 * @phpstan-type Price array{id: int, amount: int}
 * @phpstan-type Plan array{plan_id: int, name: string, trial_days: int, public: bool, prices: array<string, array<string, Price>>}
 * @phpstan-type Attempt array{attempt: int, status: string, at: CarbonImmutable, failure_code: ?string}
 * @phpstan-type InvoicePlan array{reason: string, status: string, period_start: CarbonImmutable, period_end: CarbonImmutable, issued_at: CarbonImmutable, paid_at: ?CarbonImmutable, attempts: list<Attempt>}
 * @phpstan-type SubscriptionPlan array{plan_id: int, plan_price_id: int, plan_code: string, plan_name: string, status: string, interval: string, currency: string, unit_amount_minor: int, trial_ends_at: ?CarbonImmutable, billing_anchor_at: CarbonImmutable, current_period_start: CarbonImmutable, current_period_end: CarbonImmutable, cancel_at_period_end: bool, canceled_at: ?CarbonImmutable, ended_at: ?CarbonImmutable, renewal_attempts: int, next_retry_at: ?CarbonImmutable, last_failure_code: ?string, created_at: CarbonImmutable}
 * @phpstan-type Result array{scenario: string, created_at: CarbonImmutable, payment_method: bool, subscription: ?SubscriptionPlan, invoices: list<InvoicePlan>}
 */
final class BillingScenario
{
    /** Pesos de cada escenario (suma 100). */
    private const WEIGHTS = [
        'free' => 25,
        'trial' => 10,
        'active' => 38,
        'past_due' => 8,
        'canceled' => 6,
        'canceled_unpaid' => 4,
        'incomplete' => 2,
        'none' => 7,
    ];

    /**
     * @param  array<string, Plan>  $catalog
     * @param  list<int>  $retryDays  días entre intentos de cobro (`billing.dunning.retry_days`)
     */
    public function __construct(
        private readonly array $catalog,
        private readonly array $retryDays = [1, 3, 5],
    ) {}

    /**
     * @return Result
     */
    public function plan(Randomizer $rng, CarbonImmutable $asOf): array
    {
        $scenario = $this->pickScenario($rng);

        return match ($scenario) {
            'free' => $this->free($rng, $asOf),
            'trial' => $this->trial($rng, $asOf),
            'active' => $this->paid($rng, $asOf, 'active'),
            'past_due' => $this->paid($rng, $asOf, 'past_due'),
            'canceled' => $this->paid($rng, $asOf, 'canceled'),
            'canceled_unpaid' => $this->paid($rng, $asOf, 'canceled_unpaid'),
            'incomplete' => $this->incomplete($rng, $asOf),
            default => $this->none($rng, $asOf),
        };
    }

    private function pickScenario(Randomizer $rng): string
    {
        $hasFree = $this->freePlan() !== null;
        $hasPaid = $this->paidPlanCodes() !== [];
        $roll = $rng->getInt(1, 100);

        foreach (self::WEIGHTS as $name => $weight) {
            if ($roll <= $weight) {
                $needsFree = $name === 'free';
                $needsPaid = ! in_array($name, ['free', 'none'], true);

                if (($needsFree && ! $hasFree) || ($needsPaid && ! $hasPaid)) {
                    return 'none';
                }

                if ($name === 'trial' && ! $this->hasTrialPlan()) {
                    return 'active';
                }

                return $name;
            }
            $roll -= $weight;
        }

        return 'none';
    }

    /**
     * @return Result
     */
    private function none(Randomizer $rng, CarbonImmutable $asOf): array
    {
        return ['scenario' => 'none', 'created_at' => $this->ago($rng, $asOf, 1, 300), 'payment_method' => false, 'subscription' => null, 'invoices' => []];
    }

    /**
     * Plan gratuito: suscripción `active` sin facturas (docs/billing.md §Contratar).
     *
     * @return Result
     */
    private function free(Randomizer $rng, CarbonImmutable $asOf): array
    {
        [$code, $price, $currency, $interval] = $this->freePlan() ?? throw new \LogicException('Sin plan gratuito.');
        $created = $this->ago($rng, $asOf, 1, 400);
        [$start, $end] = $this->currentPeriod($created, $created, BillingInterval::from($interval), $asOf);

        return [
            'scenario' => 'free',
            'created_at' => $created,
            'payment_method' => false,
            'subscription' => $this->subscription($code, $price, $currency, $interval, 'active', $created, $start, $end, $created),
            'invoices' => [],
        ];
    }

    /**
     * Prueba en curso: `trialing`, sin factura ni cobro hasta que termine.
     *
     * @return Result
     */
    private function trial(Randomizer $rng, CarbonImmutable $asOf): array
    {
        $code = $this->pickPaidPlan($rng, requireTrial: true);
        [$price, $currency, $interval] = $this->pickPrice($rng, $code);
        $trialDays = $this->catalog[$code]['trial_days'];

        $created = $asOf->subSeconds($rng->getInt(3600, max(3601, ($trialDays - 1) * 86400)));
        $trialEnd = $created->addDays($trialDays);

        $subscription = $this->subscription($code, $price, $currency, $interval, 'trialing', $created, $created, $trialEnd, $trialEnd);
        $subscription['trial_ends_at'] = $trialEnd;

        return ['scenario' => 'trial', 'created_at' => $created, 'payment_method' => $rng->getInt(1, 100) <= 50, 'subscription' => $subscription, 'invoices' => []];
    }

    /**
     * Contratos de pago con historial de facturas.
     *
     * @param  'active'|'past_due'|'canceled'|'canceled_unpaid'  $kind
     * @return Result
     */
    private function paid(Randomizer $rng, CarbonImmutable $asOf, string $kind): array
    {
        $code = $this->pickPaidPlan($rng);
        [$price, $currency, $interval] = $this->pickPrice($rng, $code);
        $iv = BillingInterval::from($interval);
        $year = $iv === BillingInterval::Year;

        // Cuántos periodos hay desde el primero hasta el "último" (el vigente, o el que no se pudo cobrar).
        $k = $year ? $rng->getInt(0, 2) : $rng->getInt(0, 11);
        if ($kind === 'past_due' || $kind === 'canceled_unpaid') {
            $k = max(1, $k);
        }

        // Antigüedad del inicio del último periodo, según el escenario. Los márgenes absorben el desfase de
        // hasta 3 días que deja el redondeo de fin de mes al reconstruir los periodos hacia adelante.
        $offsetDays = match ($kind) {
            'active' => $year ? $rng->getInt(0, 330) : $rng->getInt(0, 21),
            'past_due' => $rng->getInt(0, 5),
            default => ($year ? 375 : 35) + $rng->getInt(0, 150),
        };
        $lastStart = $asOf->subDays($offsetDays)->subSeconds($rng->getInt(3600, 80000));
        $firstStart = $lastStart->subMonthsNoOverflow($k * $iv->months());

        $periods = $this->periods($firstStart, $iv, $k + 1);
        $last = $periods[$k];

        $paidUpTo = $kind === 'canceled_unpaid' || $kind === 'past_due' ? $k - 1 : $k;
        $invoices = [];

        foreach ($periods as $i => [$start, $end]) {
            if ($i > $paidUpTo) {
                break;
            }
            $paidAt = $start->addSeconds($rng->getInt(2, 300));
            $invoices[] = [
                'reason' => $i === 0 ? 'subscription_create' : 'subscription_cycle',
                'status' => 'paid',
                'period_start' => $start,
                'period_end' => $end,
                'issued_at' => $start,
                'paid_at' => $paidAt,
                'attempts' => [['attempt' => 1, 'status' => 'succeeded', 'at' => $paidAt, 'failure_code' => null]],
            ];
        }

        $status = 'active';
        $current = $last;
        $cancelAtPeriodEnd = false;
        $canceledAt = null;
        $endedAt = null;
        $renewalAttempts = 0;
        $nextRetry = null;
        $failureCode = null;

        if ($kind === 'past_due' || $kind === 'canceled_unpaid') {
            $failed = $kind === 'past_due' ? 1 : 1 + count($this->retryDays);
            $attempts = [];
            $at = $last[0]->addSeconds(60);

            for ($n = 1; $n <= $failed; $n++) {
                $attempts[] = ['attempt' => $n, 'status' => 'failed', 'at' => $at, 'failure_code' => 'card_declined'];
                $at = $at->addDays($this->retryDays[$n - 1] ?? 1);
            }

            $invoices[] = [
                'reason' => 'subscription_cycle',
                'status' => $kind === 'past_due' ? 'open' : 'uncollectible',
                'period_start' => $last[0],
                'period_end' => $last[1],
                'issued_at' => $last[0],
                'paid_at' => null,
                'attempts' => $attempts,
            ];

            // La suscripción sigue en el periodo anterior (el nuevo solo avanza al cobrar).
            $current = $periods[$k - 1];
            $renewalAttempts = $failed;
            $failureCode = 'card_declined';
            $status = $kind === 'past_due' ? 'past_due' : 'canceled';

            if ($kind === 'past_due') {
                $nextRetry = $attempts[$failed - 1]['at']->addDays($this->retryDays[$failed - 1] ?? 1);
            } else {
                $endedAt = $attempts[$failed - 1]['at'];
                $canceledAt = $endedAt;
            }
        } elseif ($kind === 'canceled') {
            $status = 'canceled';
            $cancelAtPeriodEnd = true;
            $canceledAt = $last[0]->addDays($rng->getInt(1, 20));
            $endedAt = $last[1];
        }

        $subscription = $this->subscription($code, $price, $currency, $interval, $status, $firstStart, $current[0], $current[1], $firstStart);
        $subscription['cancel_at_period_end'] = $cancelAtPeriodEnd;
        $subscription['canceled_at'] = $canceledAt;
        $subscription['ended_at'] = $endedAt;
        $subscription['renewal_attempts'] = $renewalAttempts;
        $subscription['next_retry_at'] = $nextRetry;
        $subscription['last_failure_code'] = $failureCode;

        return ['scenario' => $kind, 'created_at' => $firstStart, 'payment_method' => true, 'subscription' => $subscription, 'invoices' => $invoices];
    }

    /**
     * Alta de pago rechazada: `incomplete`, factura `open` y un intento fallido (docs/billing.md §Contratar).
     *
     * @return Result
     */
    private function incomplete(Randomizer $rng, CarbonImmutable $asOf): array
    {
        $code = $this->pickPaidPlan($rng);
        [$price, $currency, $interval] = $this->pickPrice($rng, $code);
        $iv = BillingInterval::from($interval);
        $start = $asOf->subSeconds($rng->getInt(600, 20 * 3600));
        $end = PeriodCalculator::end($start, $start, $iv);

        $subscription = $this->subscription($code, $price, $currency, $interval, 'incomplete', $start, $start, $end, $start);
        $subscription['last_failure_code'] = 'card_declined';

        return [
            'scenario' => 'incomplete',
            'created_at' => $start,
            'payment_method' => true,
            'subscription' => $subscription,
            'invoices' => [[
                'reason' => 'subscription_create',
                'status' => 'open',
                'period_start' => $start,
                'period_end' => $end,
                'issued_at' => $start,
                'paid_at' => null,
                'attempts' => [['attempt' => 1, 'status' => 'failed', 'at' => $start->addSeconds(5), 'failure_code' => 'card_declined']],
            ]],
        ];
    }

    /**
     * @return SubscriptionPlan
     */
    private function subscription(string $code, int $priceId, string $currency, string $interval, string $status, CarbonImmutable $anchor, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $createdAt): array
    {
        $plan = $this->catalog[$code];
        $amount = $plan['prices'][$currency][$interval]['amount'];

        return [
            'plan_id' => $plan['plan_id'],
            'plan_price_id' => $priceId,
            'plan_code' => $code,
            'plan_name' => $plan['name'],
            'status' => $status,
            'interval' => $interval,
            'currency' => $currency,
            'unit_amount_minor' => $amount,
            'trial_ends_at' => null,
            'billing_anchor_at' => $anchor,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'renewal_attempts' => 0,
            'next_retry_at' => null,
            'last_failure_code' => null,
            'created_at' => $createdAt,
        ];
    }

    /**
     * Periodos consecutivos anclados en el primero (misma regla que la renovación real).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function periods(CarbonImmutable $first, BillingInterval $interval, int $count): array
    {
        $periods = [];
        $start = $first;

        for ($i = 0; $i < $count; $i++) {
            $end = PeriodCalculator::end($start, $first, $interval);
            $periods[] = [$start, $end];
            $start = $end;
        }

        return $periods;
    }

    /**
     * Periodo de un plan gratuito que contiene `$asOf` (se renueva sin factura).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function currentPeriod(CarbonImmutable $first, CarbonImmutable $anchor, BillingInterval $interval, CarbonImmutable $asOf): array
    {
        $start = $first;

        for ($i = 0; $i < 600; $i++) {
            $end = PeriodCalculator::end($start, $anchor, $interval);

            if ($end->greaterThan($asOf)) {
                return [$start, $end];
            }
            $start = $end;
        }

        return [$start, PeriodCalculator::end($start, $anchor, $interval)];
    }

    private function ago(Randomizer $rng, CarbonImmutable $asOf, int $minDays, int $maxDays): CarbonImmutable
    {
        return $asOf->subDays($rng->getInt($minDays, $maxDays))->subSeconds($rng->getInt(0, 86399));
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: string}|null código, id del precio, moneda e intervalo del precio gratuito
     */
    private function freePlan(): ?array
    {
        foreach ($this->catalog as $code => $plan) {
            foreach ($plan['prices'] as $currency => $byInterval) {
                foreach ($byInterval as $interval => $price) {
                    if ($price['amount'] === 0 && $interval === 'month') {
                        return [$code, $price['id'], $currency, $interval];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function paidPlanCodes(): array
    {
        $codes = [];

        foreach ($this->catalog as $code => $plan) {
            foreach ($plan['prices'] as $byInterval) {
                foreach ($byInterval as $price) {
                    if ($price['amount'] > 0) {
                        $codes[] = $code;
                        continue 3;
                    }
                }
            }
        }

        return $codes;
    }

    private function hasTrialPlan(): bool
    {
        foreach ($this->paidPlanCodes() as $code) {
            if ($this->catalog[$code]['trial_days'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function pickPaidPlan(Randomizer $rng, bool $requireTrial = false): string
    {
        $codes = array_values(array_filter(
            $this->paidPlanCodes(),
            fn (string $c) => ! $requireTrial || $this->catalog[$c]['trial_days'] > 0,
        ));
        $public = array_values(array_filter($codes, fn (string $c) => $this->catalog[$c]['public']));

        // Los planes privados (acuerdos a medida) son la excepción.
        $pool = $public !== [] && ($public === $codes || $rng->getInt(1, 100) <= 90) ? $public : $codes;

        return $pool[$rng->getInt(0, count($pool) - 1)];
    }

    /**
     * @return array{0: int, 1: string, 2: string} id del precio, moneda e intervalo (con importe > 0)
     */
    private function pickPrice(Randomizer $rng, string $code): array
    {
        $options = [];

        foreach ($this->catalog[$code]['prices'] as $currency => $byInterval) {
            foreach ($byInterval as $interval => $price) {
                if ($price['amount'] > 0) {
                    $options[] = [$price['id'], (string) $currency, (string) $interval];
                }
            }
        }

        // Mensual domina (75 %) y la primera moneda del catálogo (70 %).
        $month = array_values(array_filter($options, fn (array $o) => $o[2] === 'month'));
        $pool = $month !== [] && ($month === $options || $rng->getInt(1, 100) <= 75) ? $month : $options;
        $firstCurrency = $pool[0][1];
        $main = array_values(array_filter($pool, fn (array $o) => $o[1] === $firstCurrency));

        if ($main !== [] && $main !== $pool && $rng->getInt(1, 100) <= 70) {
            $pool = $main;
        }

        return $pool[$rng->getInt(0, count($pool) - 1)];
    }
}
