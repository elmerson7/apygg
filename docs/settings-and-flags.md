# Ajustes por niveles, feature flags y límites por plan

Tres piezas genéricas y **opt-in** (sin estado estático, seguras con Octane). Todo se declara en `config/`
y se sobrescribe en BD sin deploy. Multiempresa: ver `docs/multi-tenancy.md`.

| Pieza | Declaración | Almacén | Servicio (interfaz) |
|---|---|---|---|
| Ajustes tipados | `config/settings.php` | plataforma: tabla `settings`; tenant: `tenants.settings` (jsonb plano) | `SettingsRegistryInterface` |
| Feature flags | `config/features.php` | `feature_flags` + `feature_flag_overrides` | `FeatureFlagServiceInterface` |
| Límites por plan | `config/plans.php` | `tenants.plan` + `tenant_limit_overrides` | `PlanLimitsInterface` |

## 1. Ajustes

Resolución: **valor del tenant activo -> valor de plataforma -> `default` de config**.

```php
'general.theme' => ['type' => 'enum', 'values' => ['light','dark','auto'], 'default' => 'auto', 'tenant' => true],
```

Campos: `type` (`bool|int|string|enum|json`), `default`, `values` (enum), `min`/`max` (rango del int o longitud del
string), `rules` (reglas Laravel extra), `platform` (editable por plataforma, por defecto `true`), `tenant`
(sobrescribible por cada tenant, por defecto `false`), `group`, `description`.
**Añadir un ajuste nuevo (p. ej. idioma/zona/moneda por tenant) es solo una entrada en `config/settings.php`**: no hay
que tocar código. Leer: `app(SettingsRegistryInterface::class)->get('general.theme')` (inyecta la interfaz).

No hay dos sistemas: el registro es una capa tipada sobre `settings` (CRUD genérico `/settings`, que ahora **valida las
keys declaradas** con su definición) y sobre `tenants.settings` (escrito bajo `FOR UPDATE`: dos claves distintas no se pisan).
Caché corta (`SETTINGS_CACHE_TTL`, 60 s) con invalidación al escribir; la del tenant lleva el prefijo `t<id>:` de `CacheService`,
la de plataforma no (así una escritura de plataforma invalida a todos).

| Endpoint | Permiso |
|---|---|
| `GET /settings/registry`, `PUT|DELETE /settings/registry/{key}` (plataforma) | `system.settings` |
| `GET /tenant/settings` (tenant activo) | `tenant-settings.read` |
| `PUT|DELETE /tenant/settings/{key}` | `tenant-settings.manage` |

**Escrituras del lado tenant**: todas pasan por `SettingsRegistryInterface::setTenant/resetTenant(User $actor, ...)` (único punto que toca `tenants.settings`; el `actor` queda en el rastro de actividad y es donde se engancha la comprobación de rol del actor en ese tenant). Las flags y los límites no tienen escritura del lado tenant: solo plataforma.

Errores con `code` estable: `setting_unknown` (404), `setting_not_editable` (403), `setting_invalid_value` (422, con `errors.value`),
y `tenant_required` (400) sin tenant.

## 2. Feature flags

Se declaran en `config/features.php` (`enabled`, y opcionalmente `rollout_percentage` y `rollout_by`). La fila de `feature_flags`
manda sobre el config. Orden de evaluación: **override de usuario -> override de tenant -> flag global (+ porcentaje)**.
Una flag no declarada está apagada.

- Porcentaje **estable**: cubo = `crc32(flag|sujeto|id) % 100` (`App\Support\Features\Rollout`), sin `rand()`. Mismo sujeto, mismo
  resultado; subir el porcentaje solo añade sujetos. `rollout_by` = `user` o `tenant`; con porcentaje parcial y sin sujeto, apagada.
- Uso: `FeatureFlagServiceInterface::enabled($key, $user?)` (tenant del contexto, usuario autenticado), `enabledFor($key, $userId, $tenantId)`
  (explícito, para jobs), `all()`. Compatibles: middleware `feature:<key>` y `App\Helpers\Feature` (delegan en el servicio).
- Caché corta global (`features:*`) invalidada al escribir.

| Endpoint | Permiso |
|---|---|
| `GET /admin/feature-flags` | `feature-flags.read` |
| `PUT|DELETE /admin/feature-flags/{key}`, `PUT|DELETE .../{key}/tenants/{id}`, `.../{key}/users/{id}` | `feature-flags.manage` |
| `GET /tenant/features` (flags evaluadas para el tenant y usuario) | `tenant-settings.read` |

`features-migration-to-database.md` queda como histórico: la migración ya está hecha.

## 3. Límites por plan

`config/plans.php`: `enabled` (`PLAN_LIMITS_ENABLED`, **apagado** por defecto), `default`, `limits` (claves declaradas) y `plans`
(valor por plan; `null` = ilimitado). El plan sale de `tenants.plan`; cada límite se sobrescribe por tenant en
`tenant_limit_overrides` (`value` NULL = ilimitado). Solo actúa con multiempresa y tenant en contexto. **No es facturación.**

```php
// Plantilla para un módulo: cuenta + comprueba + crea bajo bloqueo del tenant
$limits->guarded('max_widgets', fn () => Widget::count(), fn () => Widget::create($data));
$limits->ensureWithin('max_members', $current);   // o check() para solo consultar
```

Excede -> `PlanLimitReachedException`: 403 con `code` `plan_limit_reached` y `limit`, `current`, `limit_key`.
**Estrategia anti-carrera**: `guarded()` abre una transacción y bloquea la fila del tenant (`SELECT ... FOR UPDATE`) antes de contar;
las altas del mismo tenant se serializan y no pueden pasarse. Aplicado de verdad a **`max_api_keys`** (`ApiKeyService::create`; la
rotación no se bloquea en el tope y la key antigua en gracia cuenta hasta que caduca). `max_storage_mb` y `max_file_mb` los aplica el módulo de archivos
(`FileService::withStorageQuota`, ver `docs/files.md`; la unidad de la cuenta son bytes: `guarded(..., $adding, unit: 1048576)`); `max_members`
está declarado para el módulo de miembros.

| Endpoint | Permiso |
|---|---|
| `GET /admin/tenants/{id}/plan`, `PUT .../plan`, `PUT|DELETE .../limits/{key}` | `plan-limits.manage` |
| `GET /tenant/limits` | `tenant-settings.read` |
