# Auditoría de negocio

Quién cambió qué, cuándo y desde dónde, **acotado por tenant**. Es distinta de `logs_activity` (log técnico de
la app, ver `docs/logging.md`): aquí hay un diff antes/después, motivo de las acciones sensibles, consulta por
empresa y exportación.

## Decisión: tabla propia, no `spatie/laravel-activitylog`

Se crea `audit_logs` y no se instala `spatie/laravel-activitylog` porque: (1) el paquete no sabe de `tenant_id`
(habría que reescribir su modelo y sus consultas para que sea fail-closed); (2) usa `json`, y aquí las columnas
JSON son siempre `jsonb`; (3) necesitamos actor `api_key`/`system`, `request_id`, motivo y retención propia;
(4) ampliar `ActivityLog` mezclaría un log técnico con retención de `logs:clean` con un registro de negocio
inmutable. Los dos conviven: `ActivityLog` no cambia.

## Qué se guarda (`audit_logs`, migración `2026_11_20_000260`)

`tenant_id` (nullable, sin FK: el rastro sobrevive al tenant), `event` (`created|updated|deleted|restored` o el
nombre de una acción: `user.roles-change`), `auditable_type`/`auditable_id`, `actor_type` (`user|api_key|system`) y
`actor_id`, `old_values`/`new_values` (jsonb), `reason`, `ip_address`, `request_id` (= `X-Request-ID`), `created_at`.
Tabla de solo-añadir: `AuditLog` lanza `audit_immutable` si se intenta actualizar o borrar; solo `audit:prune` borra.

## Auditar un modelo: trait `Auditable`

```php
class Invoice extends Model
{
    use Auditable;                      // opt-in
    protected array $auditExclude = ['view_count'];   // opcional
    protected array $auditOnly = [];                  // opcional: solo estos
}
```

- `updated` guarda **solo los campos que cambiaron** (antes/después, con los casts aplicados); si solo cambian
  campos ocultos o excluidos, no hay entrada.
- Nunca entran los `$hidden` del modelo, `audit.exclude` (`updated_at`, `remember_token`...) ni `$auditExclude`.
  Después todo pasa por `App\Helpers\Redactor`: claves secretas (`password`, `token`, `api_key`...) y patrones
  (emails, tarjetas, JWT) se enmascaran. Consecuencia: un email dentro de un diff se ve como `p***@e***.com`.
- Tenant: el de la propia fila (`Tenant` → su id; modelo con `tenant_id` → ese) o el del contexto activo.
- La entrada se escribe **en la misma transacción** que el cambio: si se revierte, la entrada también. Un fallo
  al escribirla hace fallar la operación (la auditoría no es opcional ni "mejor esfuerzo").
- No cubre operaciones masivas de query (`Model::query()->update()`): usa el modelo o `recordAction()`.
- Aplicado a: `Tenant` (incluye `settings` y por tanto los ajustes del tenant), `TenantInvitation`, `Webhook`,
  `ApiKey`. Los miembros (`tenant_user`, tabla sin modelo) se auditan **vía servicio** como acciones:
  `tenant.member-added|role-changed|suspended|reactivated|removed`.

## Acciones de administración con motivo

```php
$reason = $audit->requireReason('user.roles-change', $request->input('reason')); // antes de actuar
...
$audit->recordAction('user.roles-change', $user, $reason, ['role_ids_after' => $ids]);
```

`audit.reason_required` (`AUDIT_REASON_REQUIRED`, lista separada por comas; vacía = ninguna) lista las acciones
que exigen `reason`; si falta, **422 `audit_reason_required`** y no se ejecuta nada. Por defecto:

| Acción | Dónde | Cómo se manda el motivo |
|---|---|---|
| `user.roles-change` | `POST /users/{id}/roles`, `DELETE /users/{id}/roles/{roleId}` | campo `reason` |
| `user.block` | `POST /admin/blacklist` | `reason` (ya era obligatorio) |
| `user.unblock` | `DELETE /admin/blacklist/{id}` | campo `reason` |
| `tenant.suspend`, `tenant.reactivate`, `tenant.limit-override`, `mail-suppression.release` | solo back-office (`docs/backoffice.md`) | campo «Motivo» del modal |

Una operación nueva (impersonar, suspender un tenant...) se añade a la lista y llama a los dos métodos de arriba.
El back-office debe hacer lo mismo al escribir.

## Endpoints

| Ruta | Permiso | Alcance |
|---|---|---|
| `GET /tenant/audit`, `GET /tenant/audit/export` | `tenant-audit.read` + rol del actor en el tenant | solo el tenant activo (`X-Tenant`) |
| `GET /admin/audit`, `GET /admin/audit/export` | `audit.read` | plataforma; filtro `tenant_id` |

`tenant-audit.read` lo tienen `tenant-owner` y `tenant-admin`. Como con equipos de Spatie apagados los roles
`tenant-*` son globales, el servicio vuelve a comprobar con `TenantRoles::actorRankFor` que el rol **del actor en
ese tenant** concede el permiso (patrón de `docs/tenant-onboarding.md`). Un `tenant_id` en la query de
`/tenant/audit` se ignora: el tenant sale siempre del contexto.

Filtros: `from`, `to`, `actor_type`, `actor_id`, `event`, `model` (clase completa o nombre corto, p. ej. `Webhook`),
`model_id`, `per_page` (máx. 100). Listado paginado en SQL, más reciente primero.

**Exportación CSV**: streaming, lectura por lotes (`audit.export.chunk`) con tope `audit.export.max_rows`, UTF-8
con BOM. Las celdas que empiezan por `= + - @` se prefijan con `'` (CSV injection). Mismos filtros y mismo
aislamiento que el listado. `ForceJsonResponse` respeta el `Content-Type` de las respuestas con
`Content-Disposition: attachment`.

## Retención

`audit:prune [--days=N] [--dry-run]`, programado a diario (03:15). Días por defecto: `AUDIT_RETENTION_DAYS`
(730); `0` = no borrar nunca. `AUDIT_ENABLED=false` apaga toda la escritura.

## Cómo probar un modelo auditado

Crea/actualiza/borra con el modelo y comprueba `AuditLog` (ver `tests/Feature/Audit/AuditoriaTest.php`);
el aislamiento está en `tests/Feature/Tenancy/Aislamiento/AuditoriaAislamientoTest.php`.
