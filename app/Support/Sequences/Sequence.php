<?php

namespace App\Support\Sequences;

use App\Contracts\SequenceInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Numeraciones atómicas por (tenant, nombre, periodo). Ver docs/sequences.md.
 *
 * `next()` bloquea la fila del contador (`SELECT ... FOR UPDATE`) dentro de una transacción: nunca
 * `count()+1` ni `max()+1`, que dan duplicados con dos peticiones a la vez. Sin estado entre llamadas
 * (Octane): el tenant sale de `TenantContext` (scoped) en cada llamada.
 */
class Sequence implements SequenceInterface
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function next(string $name, ?int $tenantId = null, array $definition = []): string|int
    {
        [$value, $row] = $this->reserve($name, $tenantId, $definition);

        return SequenceNumber::format($value, $row->prefix, (int) $row->padding, (string) $row->period);
    }

    public function nextValue(string $name, ?int $tenantId = null, array $definition = []): int
    {
        return $this->reserve($name, $tenantId, $definition)[0];
    }

    public function peek(string $name, ?int $tenantId = null, array $definition = []): string|int
    {
        $tenantId = $this->resolveTenant($tenantId);
        $def = $this->definition($name, $definition);
        $period = $this->period($def);

        $row = $this->query($tenantId, $name, $period)->first();

        if ($row === null) {
            return SequenceNumber::format($def['start'], $def['prefix'], $def['padding'], $period);
        }

        return SequenceNumber::format((int) $row->next_value, $row->prefix, (int) $row->padding, $period);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: int, 1: stdClass}
     */
    private function reserve(string $name, ?int $tenantId, array $definition): array
    {
        $tenantId = $this->resolveTenant($tenantId);
        $def = $this->definition($name, $definition);
        $period = $this->period($def);

        return DB::transaction(function () use ($tenantId, $name, $def, $period): array {
            // Bloquear la fila PRIMERO. Insertar antes (`insertOrIgnore`) sobre una fila existente toma un
            // bloqueo compartido y pedir luego el exclusivo provoca interbloqueos entre dos transacciones
            // que quieren la misma secuencia.
            $row = $this->query($tenantId, $name, $period)->lockForUpdate()->first();

            if ($row === null) {
                // Primer uso: se crea (un creador concurrente se ignora) y se bloquea.
                DB::table('sequences')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'period' => $period,
                    'prefix' => $def['prefix'],
                    'padding' => $def['padding'],
                    'next_value' => $def['start'],
                    'max_value' => $def['max'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = $this->query($tenantId, $name, $period)->lockForUpdate()->first()
                    ?? throw new RuntimeException("No se pudo crear la secuencia [{$name}].");
            }

            $value = (int) $row->next_value;

            if ($row->max_value !== null && $value > (int) $row->max_value) {
                throw new SequenceExhausted("La secuencia [{$name}] llegó a su máximo ({$row->max_value}).");
            }

            DB::table('sequences')->where('id', $row->id)->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return [$value, $row];
        });
    }

    /**
     * Tenant explícito, o el del contexto con multiempresa activo (sin tenant: MissingTenantContext),
     * o null (secuencia global) con multiempresa apagado o dentro de `TenantQuery::withoutTenant()`.
     */
    private function resolveTenant(?int $explicit): ?int
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if (! $this->tenants->enforcing()) {
            return null;
        }

        return $this->tenants->requireId('Sequence', 'reservar un número de');
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array{prefix: ?string, padding: int, start: int, max: ?int, reset: string}
     */
    private function definition(string $name, array $override): array
    {
        /** @var array<string, mixed> $merged */
        $merged = array_merge(
            (array) config('sequences.defaults', []),
            (array) config("sequences.definitions.{$name}", []),
            $override,
        );

        $prefix = isset($merged['prefix']) ? (string) $merged['prefix'] : null;

        return [
            'prefix' => $prefix === '' ? null : $prefix,
            'padding' => max(0, min(20, (int) ($merged['padding'] ?? 0))),
            'start' => max(1, (int) ($merged['start'] ?? 1)),
            'max' => isset($merged['max']) ? (int) $merged['max'] : null,
            'reset' => (string) ($merged['reset'] ?? 'none'),
        ];
    }

    /**
     * @param  array{reset: string}  $def
     */
    private function period(array $def): string
    {
        return $def['reset'] === 'yearly' ? now()->format('Y') : '';
    }

    private function query(?int $tenantId, string $name, string $period): Builder
    {
        $query = DB::table('sequences')->where('name', $name)->where('period', $period);

        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }
}
