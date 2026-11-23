# Back-office interno (Filament) — módulo opcional

Panel web para **el dueño de la plataforma**: usuarios, roles, logs, correo, ajustes y lista negra.
Está hecho con Filament 5 (Livewire 4) y vive en `/backoffice`.

## Qué es y qué no es

- **Es** una herramienta interna de administración. Usa los **mismos servicios y policies** que la API
  (`UserService`, `RoleService`, `SettingsService`, `UserPolicy`, `RolePolicy`...): las reglas de
  jerarquía de admins, "no des permisos que no tienes" y "último admin protegido" no se duplican.
- **No es** el panel de los clientes de cada SaaS. Ese sigue siendo API + frontend propio.
- **No** sustituye a la API: no hay lógica de negocio nueva, solo una interfaz sobre la existente.

## Acceso

Requisitos, todos a la vez:

1. Sesión web (guard `web`) con el mismo modelo `User`.
2. Permiso `backoffice.access` (o ser `admin`, que pasa cualquier permiso).
3. MFA por app (TOTP) configurado: sin él, Filament redirige a la pantalla de configuración antes de dejar entrar.
4. Cuenta activa (no desactivada) y fuera de la lista negra. Se comprueba en cada petición (`User::canAccessPanel`).

El login limita los intentos (Filament: 5 por minuto y por IP). La IP real tras Cloudflare la resuelve
`ResolveClientIpMiddleware`, que está en el middleware del panel.

### Guard `web` frente a permisos del guard `api`

Filament autentica por sesión (`web`), pero los roles y permisos de la app son del guard `api`.
`User::$guard_name = 'api'` hace que Spatie los resuelva siempre contra `api`, sea cual sea el guard de la
petición, así que `$user->can('backoffice.access')` funciona desde la sesión web y respeta al super admin
(`Gate::after`). El panel fija `->authGuard('web')` de forma explícita porque, bajo Octane, el guard por
defecto puede haber cambiado a `api` por una petición anterior.

### Crear el primer acceso

```bash
make art cmd="migrate"                    # añade las columnas del MFA a users
make art cmd="db:seed --class=PermissionSeeder"   # siembra backoffice.access
make art cmd="db:seed --class=RoleSeeder"          # lo da al rol admin
```

1. Entra en `https://<tu-dominio>/backoffice` con un usuario admin (el de `ADMIN_EMAIL`/`ADMIN_PASSWORD`).
2. La primera vez Filament pide configurar el MFA: escanea el QR con una app TOTP y **guarda los códigos de recuperación**.
3. Para dar acceso a otra persona: asígnale el permiso `backoffice.access` (por rol o directo). Solo verá los
   recursos para los que tenga permiso (`users.read`, `system.logs`, `mail.manage`...).

## Qué incluye

| Grupo | Recurso | Permiso / policy | Operaciones |
|---|---|---|---|
| Acceso | Usuarios | `UserPolicy` | listar, ver, crear, editar perfil, activar/desactivar, asignar roles |
| Acceso | Roles | `RolePolicy` | crear, editar, eliminar, permisos (exige `roles.manage-permissions`) |
| Acceso | Permisos | `permissions.read` | solo lectura (se definen en `PermissionSeeder`) |
| Registros | Actividad, API, Seguridad | `system.logs` | solo lectura, con filtros; los secretos se redactan al mostrar |
| Correo | Cuentas, Envíos | `mail.manage`, `mail.logs` | solo lectura |
| Correo | Plantillas | `mail.manage` | ver, pausar y reanudar |
| Sistema | Ajustes | `SettingsPolicy` (admin) | crear, editar, eliminar; los valores cifrados no se muestran |
| Sistema | Lista negra | `security.blacklist` | bloquear y desbloquear |
| Dashboard | Salud | — | widget que reutiliza `HealthController::detailed()` |

### Recursos de plataforma (módulos nuevos)

| Grupo | Recurso | Permiso | Operaciones y servicio |
|---|---|---|---|
| Plataforma | Empresas | `tenants.read` / `tenants.manage` (+ `plan-limits.manage`) | listado, búsqueda, ficha con miembros e invitaciones (solo lectura); suspender/reactivar (`TenantAdminServiceInterface`, motivo auditado), cambiar plan y override de límite (`PlanLimitsInterface`, motivo auditado). Solo aparece con multiempresa activo |
| Plataforma | Auditoría | `audit.read` | solo lectura; filtros por empresa, actor, tipo de actor, evento y fecha; diff con `Pretty::json` |
| Plataforma | Feature flags | `feature-flags.read` / `.manage` | crear/editar/restablecer (`FeatureFlagServiceInterface`; solo claves de `config/features.php`) y overrides por empresa |
| Correo | Supresión | `mail.suppressions` | alta manual y quitar (`MailSuppressionServiceInterface`; quitar exige motivo auditado) |
| Privacidad | Documentos legales | `legal-documents.read` / `.manage` | publicar versión nueva (`ConsentServiceInterface::publish`, inmutable); la ficha agrega aceptaciones sin datos personales |
| Privacidad | Datos personales exportados | `users.read` | solo lectura (nunca disco ni ruta) |
| Datos | Exportaciones, Importaciones | `users.export`, `users.import` | solo lectura (nunca disco ni ruta) |
| Sistema | Mantenimiento | `maintenance.manage` | página: activar con mensaje y fin / desactivar (`MaintenanceServiceInterface`) |

Los modelos multiempresa (`TenantInvitation`) se leen en la ficha con `TenantQuery::withoutTenant()` (trait
`QueriesWithoutTenant`, deja rastro en el log de seguridad) y solo para lectura. Las acciones sensibles piden
motivo con `AuditServiceInterface::requireReason` antes de actuar y lo registran con `recordAction`.

"Desactivar" un usuario es el borrado lógico de `UserService::delete`, igual que `DELETE /users/{id}`.
No hay borrado definitivo desde el panel.

## Seguridad y Octane

- Sin estado estático en el módulo. Livewire funciona con Octane.
- `SecurityHeadersMiddleware` es del grupo `api` y no afecta al panel (su CSP de API bloquearía Filament).
  El panel lleva `BackofficeSecurityHeaders`: `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`,
  `Permissions-Policy`, `Cache-Control: no-store` y HSTS en HTTPS. No se fija CSP porque Alpine/Livewire
  necesitan evaluación dinámica; si quieres una, añádela en ese middleware con nonces.
- Las rutas del panel usan sesión, cookies cifradas y CSRF estándar (no el grupo `api`).
- `bootstrap/app.php` no aplica el render JSON de la API a `/backoffice/*` ni a `livewire*`.
- Sin policy, Filament deniega. Los modelos que la API protege solo con middleware (logs, correo, lista
  negra) tienen una policy propia en `app/Filament/Policies/`, registrada en el provider.

## Assets

Filament publica sus assets con `php artisan filament:upgrade`, que está en `post-autoload-dump` de
`composer.json` (se ejecuta en `composer install`, en el build de la imagen y en el entrypoint). Se publican
en `public/css/filament`, `public/js/filament` y `public/fonts/filament`, que están en `.gitignore`: son
generados y dependen de la versión instalada; versionarlos solo añade ruido en cada actualización.

## Añadir un recurso del proyecto

1. `make art cmd="make:filament-resource Pedido --panel=backoffice --view"` (se descubre solo en `app/Filament/Resources`).
2. Define el `slug` y el `navigationGroup`.
3. **Autoriza con la policy del modelo.** Si el modelo no tiene, créala; si solo tiene middleware de permiso en
   la API, extiende `PermissionBasedPolicy` y regístrala en `BackofficePanelProvider::boot()`.
4. **Escribe llamando al servicio** (`app(PedidoService::class)->...`) en `handleRecordCreation` / `handleRecordUpdate`
   y en las acciones; no hagas `$record->update()` si existe servicio. Envuelve con `Failure::guard()`.
5. Solo lectura: usa el trait `IsReadOnly` y muestra JSON con `Pretty::json()`.
6. Si añades un permiso, ponlo en `PermissionSeeder::catalog()`.
7. Test en `tests/Feature/Backoffice/`, con el camino denegado incluido.

## Quitar el módulo

1. `composer remove filament/filament` (y `livewire/livewire` si nada más lo usa).
2. Borra `app/Filament/`, `app/Providers/Filament/`, `config/backoffice.php`, `tests/Feature/Backoffice/` y este documento.
3. Quita `App\Providers\Filament\BackofficePanelProvider::class` de `bootstrap/providers.php`.
4. En `composer.json` quita `"@php artisan filament:upgrade || true"` de `post-autoload-dump`, y de `.gitignore`
   las tres líneas `public/*/filament`. Borra `public/css/filament`, `public/js/filament`, `public/fonts/filament`.
5. En `app/Models/User.php` quita `FilamentUser`, `HasAppAuthentication`, `HasAppAuthenticationRecovery`, los dos
   traits `InteractsWith...`, sus `use` y el método `canAccessPanel`.
6. En `bootstrap/app.php` quita el cierre `$isBackoffice` y sus dos usos (`shouldRenderJsonWhen` y `render`);
   `shouldRenderJsonWhen` vuelve a `return true`.
7. Quita `backoffice.access` de `PermissionSeeder::catalog()` y, en los entornos existentes, bórralo:
   `make art cmd="tinker"` → `App\Models\Permission::where('name','backoffice.access')->forceDelete();` y
   `make art cmd="permission:cache-reset"`.
8. Quita `BACKOFFICE_*` de `.env.example` y `BACKOFFICE_ENABLED` de `phpunit.xml`.
9. Opcional: las columnas `app_authentication_*` de `users` son inertes; se pueden dejar o revertir su migración.
10. `make check` y `make test`.

Para **apagarlo sin quitarlo**: `BACKOFFICE_ENABLED=false` (el panel no se registra y `/backoffice` da 404).
