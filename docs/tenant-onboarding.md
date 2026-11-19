# Alta de empresas, invitaciones y miembros del tenant

Complementa `docs/multi-tenancy.md`. Todo es **opt-in con `MULTI_TENANCY_ENABLED=true`**: con
multiempresa apagado las rutas existen pero responden **404** (`tenancy.enabled`; se evalúa por petición,
no al registrar rutas, para que valga también en Octane y en tests). Config: `config/multi-tenancy.php` →
`onboarding`.

## Roles del tenant

`tenant_user.role` es la **fuente de verdad** de la jerarquía (`owner` 30 > `admin` 20 > `member` 10, configurables
en `onboarding.roles`; cada uno con su `spatie_role`). Reglas, en `TenantMemberService`/`TenantInvitationService`:

- Nadie asigna, invita con, ni toca a un miembro de un rango **superior al suyo** (403 `tenant_role_insufficient`).
- No se puede quitar, degradar ni suspender al **último dueño activo** (422 `last_owner`); las operaciones de
  miembros bloquean la fila del tenant (`FOR UPDATE`), así que dos cambios simultáneos no lo saltan.
- Un actor sin membresía (soporte de plataforma con `tenants.access-any`) cuenta con el rango máximo, pero también
  necesita el permiso de la ruta.

El rol de Spatie homónimo (`tenant-owner`, `tenant-admin`, `tenant-member`; los crea `RoleSeeder`) solo concede los
permisos de ruta: `tenant-members.read`, `tenant-members.manage`, `tenant-invitations.manage`.

- **`PERMISSION_TEAMS=true`**: el rol se asigna y se retira **en el equipo del tenant** (un dueño en A no tiene
  permisos en B).
- **Equipos apagados** (por defecto): los roles de Spatie son globales, así que solo se **añaden** (nunca se retiran
  al cambiar de rol o quitar un miembro) y la jerarquía la impone igualmente `tenant_user.role`. Un dueño de A que es
  miembro raso de B pasa la puerta `permission:` en B, pero el servicio lo frena por su rol en B: cada operación
  exige que el rol de `tenant_user` **en ese tenant** conceda el permiso (`TenantRoles::actorRankFor()`), no solo
  que no supere su rango. Un módulo propio que gestione algo del tenant debe hacer lo mismo; el `permission:` de la
  ruta no basta con equipos apagados. Para permisos realmente por empresa, activa los equipos.

## Alta de empresa (signup)

`POST /tenants` (`auth:api`, `throttle:5,1`) `{name, slug}`. El usuario queda con membresía activa y rol `owner`
(tenant + membresía + rol de Spatie en una sola transacción).

| Config / `.env` | Efecto |
|---|---|
| `TENANT_SIGNUP_ENABLED` (false) | Apagado: 403 `tenant_signup_disabled` (solo la plataforma crea tenants) |
| `TENANT_SIGNUP_REQUIRE_APPROVAL` (false) | El tenant nace `pending` (`TenantStatus::Pending`); el middleware `tenant` lo rechaza (403 `tenant_inactive`) hasta `php artisan tenancy:approve <slug>` |
| `TENANT_TRIAL_DAYS` (vacío) | Rellena `tenants.trial_ends_at` (solo una fecha; sin cobros: fase de suscripciones) |
| `TENANT_SIGNUP_MAX_PER_USER` (10) | Tope de empresas de las que un usuario es dueño (422 `tenant_limit_reached`) |
| `onboarding.signup.reserved_slugs` | Slugs prohibidos (`www`, `api`, `admin`...) |

El slug: `a-z0-9` con guiones, 3-63, **al menos una letra** (un identificador solo numérico se interpreta como id en
el middleware), único (también frente a tenants borrados). Dos altas simultáneas del mismo slug → una 201 y la otra
**422** (la restricción única de BD decide). `GET /tenants/mine` lista las empresas del usuario con su rol.

## Invitaciones

Tabla `tenant_invitations` (con `BelongsToTenant`). **Solo se guarda `token_hash` (sha256)**; el token en claro
(64 caracteres aleatorios) existe únicamente en el correo (y en el payload de la cola mientras se envía). Un índice
único parcial impide dos pendientes para el mismo `(tenant, email)`.

Rutas dentro del tenant (`X-Tenant`, permiso `tenant-invitations.manage`):

| Método y ruta | Qué hace |
|---|---|
| `GET /tenant/invitations?status=&search=&per_page=` | Listado paginado (`pending`, `expired`, `accepted`, `revoked`) |
| `POST /tenant/invitations` `{email, role?}` | Crea y envía. Email normalizado a minúsculas; rol por defecto `member` |
| `POST /tenant/invitations/{id}/resend` | Rota el token (el anterior deja de valer) y la caducidad, y reenvía |
| `DELETE /tenant/invitations/{id}` | Revoca |

Límites: `TENANT_INVITATION_MAX_PENDING` (50, 422 `invitation_limit_reached`), `TENANT_INVITATION_TTL_HOURS` (168),
`throttle:30,1` al crear/reenviar. Una pendiente caducada no bloquea una nueva. El correo es una `Notification` en cola
(`TenantInvitationNotification`, plantilla `tenant_invitation`, ver `docs/mail-system.md`); el enlace sale de
`TENANT_INVITATION_URL` (por defecto `<FRONTEND_URL>/invitations/accept?token={token}`).

**Aceptar**: `POST /tenant/invitations/accept` `{token}` (`auth:api`, `throttle:10,1`, **sin** `X-Tenant`: el invitado aún
no es miembro). Crea la membresía activa con el rol invitado. El email del usuario debe coincidir con el invitado
(en minúsculas) salvo `TENANT_INVITATION_ALLOW_EMAIL_MISMATCH=true`. Se localiza por el hash del token con
`TenantQuery::withoutTenant` (auditado) y a partir de ahí todo ocurre dentro del tenant de la invitación.
Aceptar dos veces a la vez: la invitación se bloquea (`FOR UPDATE`), la segunda recibe 409 y el par
`(tenant, user)` es único en BD (`tests/Concurrency/InvitacionYAltaConcurrenteTest.php`).

Sin enumeración: un token que no existe da siempre 404 `invitation_invalid`; invitar solo comprueba si el email ya es
miembro **de ese tenant** (nunca revela si existe como usuario de la plataforma); las respuestas no incluyen el token
ni su hash.

## Miembros del tenant

`/users` sigue siendo de plataforma. Dentro del tenant (`X-Tenant`):

| Método y ruta | Permiso |
|---|---|
| `GET /tenant/members?status=&role=&search=&per_page=` | `tenant-members.read` (paginado en SQL sobre `tenant_user`) |
| `PATCH /tenant/members/{userId}` `{role}` | `tenant-members.manage` |
| `POST /tenant/members/{userId}/suspend` · `/reactivate` | `tenant-members.manage` (no puedes suspenderte: 422 `cannot_suspend_self`) |
| `DELETE /tenant/members/{userId}` | `tenant-members.manage` (puedes salir tú mismo si tienes el permiso y no eres el último dueño) |

El tenant sale siempre del contexto, nunca del cuerpo. Un usuario de otro tenant da 404 (no se revela). Un miembro
suspendido no entra al tenant (el middleware exige membresía `active`).

## Códigos de error propios

`tenant_signup_disabled` 403 · `tenant_limit_reached` 422 · `invitation_invalid` 404 · `invitation_expired` 410 ·
`invitation_revoked` 410 · `invitation_used` 409 · `invitation_email_mismatch` 403 · `invitation_already_pending` 409 ·
`invitation_limit_reached` 422 · `already_member` 409 · `tenant_role_insufficient` 403 · `last_owner` 422 ·
`cannot_suspend_self` 422 · `invalid_state` 422. Son estables (`TenantOnboardingException`); el 404 de miembros usa `not_found`.

## Comandos

`tenancy:approve <tenant>` (pending → active) · `tenancy:set-role <tenant> <email> <rol>` (nombrar al primer dueño en
instalaciones con membresías anteriores).

## Límites conocidos

- La caducidad de invitaciones se evalúa al consultar/aceptar; no hay job que las purgue (las filas se conservan como historial).
- No hay verificación de email del invitado más allá de la coincidencia con el email de la cuenta.
- El tenant `pending` responde con el mismo 403 que uno suspendido.
