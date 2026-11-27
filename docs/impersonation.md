# Impersonación de soporte

Permite a una persona de soporte **actuar como otro usuario** para reproducir un problema, con motivo
obligatorio, token de vida corta, rutas sensibles bloqueadas y rastro en la auditoría (`docs/audit.md`).

## Flujo

`POST /admin/impersonate/{id}` (permiso `users.impersonate`), cuerpo `{ "reason": "Ticket 123", "tenant_id": 5 }`:

- `reason` es **obligatorio** (`impersonation.start` está en `audit.reason_required`): sin él, 422 `audit_reason_required`.
- `tenant_id` es opcional (solo con multiempresa): acota la sesión a esa empresa; el objetivo debe ser miembro activo.
- Respuesta: `access_token` (JWT), `expires_in` y `impersonating`. **No hay refresh token**: al caducar
  (`IMPERSONATION_TTL`, 15 min) se impersona de nuevo.

El token es un access token normal del usuario suplantado (`sub` = objetivo) con claims extra: `imp` (id de quien
suplanta), `imp_tenant` (opcional) y `exp` corto. Lo emite `ImpersonationService` usando `TokenService`; el guard
JWT no cambia. Un claim `imp` solo lo puede poner el servidor (el token va firmado).

`POST /admin/impersonate/stop` (solo `auth:api`, lo llama el propio token) revoca el token y lo audita; con una
sesión normal responde 409 `not_impersonating`. También se puede dejar caducar.

## Convivencia con las sesiones (`docs/sessions-and-2fa.md`)

Cada impersonación abre una **sesión propia** del usuario suplantado (`user_sessions`, dispositivo «Soporte
(impersonación)») y el token lleva su `sid`. Así sigue valiendo con `AUTH_REQUIRE_SESSION=true`, `stop` la revoca,
y un cambio/reset de contraseña o «cerrar otras sesiones» del usuario la corta también. El usuario la ve en su lista
de sesiones (transparencia) y puede revocarla. No lleva refresh token, así que nunca se rota ni se renueva.

## Quién se puede impersonar

Prohibido (403 `impersonation_target_forbidden`): a uno mismo; a un super admin (rol `admin`, también el de
plataforma); a quien también tenga `users.impersonate` (sin cadenas); a quien tenga **permisos que el actor no tiene**
(salvo que el actor sea super admin); a identidades en lista negra. Impersonando no se puede volver a impersonar.

## Qué se bloquea mientras se impersona

`EnforceImpersonation` (grupo `api`) responde 403 `impersonation_forbidden` en las rutas de
`config/impersonation.php` → `blocked_routes` (`METODO ruta` con `*`): cambiar o resetear contraseña, editar el
perfil/usuarios, 2FA (`user/2fa*`), verificación de correo, cierre de sesiones ajenas (`DELETE user/sessions*`), correo, borrar cuenta, API keys, impersonar, el modo
mantenimiento y todo lo que sobreviviría a la impersonación: escribir roles y permisos, crear empresas, invitar
o cambiar miembros, crear o cambiar webhooks y escribir en la suscripción (`tenant/billing/*`). Leer sí se puede. **Añade aquí las rutas sensibles de tu proyecto** (pagos, exportaciones de datos personales...).
Con el token acotado a una empresa, el middleware fija `X-Tenant` a ella y rechaza otra con 403
`impersonation_tenant_mismatch` (requiere la estrategia `header` de `multi-tenancy.resolution.strategies`, la
primera por defecto).

Toda respuesta a una petición impersonada lleva `X-Impersonating: <id de quien suplanta>` para que el front muestre un aviso.

## Auditoría

- `impersonation.start` (actor = soporte, sujeto = objetivo, motivo) y `impersonation.stop`.
- `audit_logs.impersonator_id` (columna del núcleo, creada con `create_audit_logs_table`; inerte si se quita el módulo). **Toda** entrada de auditoría escrita durante una
  petición impersonada (modelos `Auditable`, `recordAction`) lleva `actor_id` = usuario suplantado e
  `impersonator_id` = quien lo hace de verdad. Sale en los listados y en el CSV (última columna).
- `App\Support\Impersonation::actorId()` da el impersonador dentro de una petición (para logs propios).

## Config

`IMPERSONATION_ENABLED` (true), `IMPERSONATION_TTL` (minutos, 15), `blocked_routes`. El permiso `users.impersonate`
no se asigna a ningún rol por defecto (solo el super admin lo tiene vía `Gate::after`).
