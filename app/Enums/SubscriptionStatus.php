<?php

namespace App\Enums;

/**
 * Estados de una suscripción y su máquina de transiciones (docs/billing.md §Estados).
 *
 * Es la ÚNICA tabla de transiciones permitidas: `SubscriptionLifecycle::transition()` la consulta y lanza
 * `invalid_subscription_transition` ante cualquier otra. `canceled` y `expired` son terminales: volver a
 * suscribirse crea una fila nueva.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Incomplete = 'incomplete';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Fin de prueba: cobra (active) o falla (past_due); la baja pedida o la inmediata la cancelan.
            self::Trialing => [self::Active, self::PastDue, self::Canceled],
            // Renovación fallida -> past_due; baja -> canceled. (active -> active es una renovación, no una transición.)
            self::Active => [self::PastDue, self::Canceled],
            // Cobro recuperado -> active; reintentos agotados -> canceled o expired (config); baja -> canceled.
            self::PastDue => [self::Active, self::Canceled, self::Expired],
            // Primer pago ok -> active; nunca se pagó en el plazo -> expired; el cliente desiste -> canceled.
            self::Incomplete => [self::Active, self::Expired, self::Canceled],
            self::Canceled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Viva: ocupa el único hueco por tenant (índice parcial en BD). */
    public function isLive(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Incomplete], true);
    }

    /** ¿Da derecho a usar el plan? `past_due` conserva el acceso mientras dure la gracia de los reintentos. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Canceled || $this === self::Expired;
    }

    /**
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_values(array_filter(self::cases(), fn (self $s) => $s->isLive())));
    }

    /**
     * @return list<string>
     */
    public static function accessValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_values(array_filter(self::cases(), fn (self $s) => $s->grantsAccess())));
    }
}
