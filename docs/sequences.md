# Numeraciones atómicas

Facturas (`F-2026-000042`), pedidos, tickets, envíos: un número correlativo que **dos peticiones
simultáneas nunca pueden repetir**. `count()+1` y `max()+1` dan duplicados (ambas leen el mismo valor
antes de que ninguna escriba); aquí el contador es una fila bloqueada con `SELECT ... FOR UPDATE`.

## Uso

Inyecta la interfaz (`App\Contracts\SequenceInterface`, binding en `ContractServiceProvider`):

```php
public function __construct(private SequenceInterface $sequences) {}

DB::transaction(function () {
    $invoice = new Invoice(['number' => $this->sequences->next('invoice')]);  // "F-2026-000042"
    $invoice->save();
});
```

| Método | Devuelve |
|---|---|
| `next(name, ?tenantId, definition = [])` | siguiente número **formateado**: `string` con prefijo/relleno, `int` sin ellos |
| `nextValue(...)` | siempre el `int` sin formato |
| `peek(...)` | lo que daría `next()` ahora, **sin reservar ni crear**. Solo orientativo: otro proceso puede tomarlo |

`SequenceExhausted` se lanza al superar `max` (y no consume número).

## Definición

`config/sequences.php` → `definitions.<nombre>` (solo se lee **al crear** la secuencia; luego manda la
fila de `sequences`; el tercer parámetro de `next()` sobrescribe la config en esa creación):

```php
'invoice' => ['prefix' => 'F-{year}-', 'padding' => 6, 'start' => 1, 'max' => 999999, 'reset' => 'yearly'],
```

- `prefix`: texto delante; `{year}` se sustituye por el año del periodo.
- `padding`: ceros a la izquierda (`6` → `000042`); un número más largo no se trunca.
- `reset`: `none` (por defecto) o `yearly`: una fila y numeración nuevas por año natural (zona horaria de la app). El año anterior queda intacto.

## Tabla `sequences`

Una fila por `(tenant_id, name, period)`, única con `NULLS NOT DISTINCT` (PostgreSQL 15+): `tenant_id` NULL
es la secuencia global. Columnas: `prefix`, `padding`, `next_value`, `max_value`, timestamps. `CHECK` en
`next_value >= 1` y `padding` 0-20. Borrar un tenant borra sus secuencias (cascade).

## Multiempresa

- Con `MULTI_TENANCY_ENABLED=true`, `tenantId = null` usa el tenant del `TenantContext`; **sin tenant en
  el contexto lanza `MissingTenantContext`** (fail-closed), salvo dentro de `TenantQuery::withoutTenant()`
  (secuencia global de plataforma). Cada tenant numera aparte.
- Con multiempresa apagado, `null` es la secuencia global. Un `$tenantId` explícito siempre se respeta.

## Garantías y límites

- **Sin duplicados** entre procesos: la fila se bloquea antes de leer y se actualiza en la misma transacción.
- **Sin huecos si se usa dentro de la transacción que guarda el documento**: si esta se revierte, el número
  vuelve a estar libre. El bloqueo se mantiene hasta el commit, así que **mantén esa transacción corta**:
  las peticiones de la misma secuencia se ponen en cola detrás de ella.
- **Fuera de una transacción** el número se confirma al instante: si el documento falla después, queda un
  hueco. Para numeración fiscal sin huecos, reserva dentro de la misma transacción que el documento.
- Crear la secuencia la primera vez es seguro: bloquear primero; si no existe, `insertOrIgnore` y volver a
  bloquear (un creador concurrente se ignora). No inviertas el orden: provoca interbloqueos.
- No hay reinicio manual ni salto hacia atrás: es una decisión de negocio; hazla con una migración o
  comando explícito y auditado.

## Pruebas

`tests/Feature/Support/SequenceTest.php` (uso, formato, reinicio anual, máximo, rollback, tenants) y
`tests/Concurrency/SecuenciaConcurrenteTest.php` (procesos reales: números distintos y consecutivos 1..N,
tenants independientes). Una implementación ingenua con `count()+1` o `max()+1` hace fallar las de concurrencia.
