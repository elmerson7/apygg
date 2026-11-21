# Sesiones, refresh rotado, 2FA, verificación de correo y versión mínima

Norma de autenticación por encima de JWT (`docs/permissions.md` cubre autorización). Todo es
autoservicio del usuario en sesión: las rutas solo piden `auth:api` (como `/user/profile`), no
permisos de catálogo.

## Sesiones (`user_sessions`)

- Cada login (y cada registro) crea una sesión: `public_id` (uuid), dispositivo resumido
  ("Chrome en Windows"), IP, último uso y estado. Los dos JWT llevan su id en el claim `sid`.
- `ApiJwtGuard` rechaza un access cuya sesión esté revocada (consulta cacheada
  `auth.sessions.cache_ttl` s; revocar olvida la clave, así que es inmediato). Solo se cachea el "activa".
- `GET /user/sessions` (la actual lleva `current: true`), `DELETE /user/sessions/{id}` (404 si no es tuya
  o ya está revocada), `DELETE /user/sessions` (todas menos la actual). Logout revoca la sesión.
- Cambio/reset de contraseña, bloqueo y `User::revokeAllTokens()` suben `token_version` (invalida todo) y
  además marcan las sesiones como revocadas.
- Tokens sin `sid` (anteriores o creados a mano) valen salvo `AUTH_REQUIRE_SESSION=true`.
- Las sesiones son por usuario, no por tenant (la identidad es global): se acotan siempre por `user_id`.

## Refresh rotado y reutilización

Cada `POST /auth/refresh` emite un refresh nuevo. La sesión guarda el `jti` vigente (`refresh_jti`), el anterior
(`previous_refresh_jti`) y cuándo se rotó (`refresh_rotated_at`). La rotación es un
`UPDATE ... WHERE refresh_jti = <el presentado> AND revoked_at IS NULL`: de dos refresh simultáneos con el mismo
token gana uno (200).

Qué pasa con un refresh que ya no es el vigente:

| Se presenta | Resultado |
|---|---|
| El vigente | 200, rota |
| El **inmediatamente anterior**, dentro de `auth.sessions.refresh_reuse_grace_seconds` (env `AUTH_REFRESH_REUSE_GRACE`, 10 s) | 401 `refresh_superseded`, **la sesión sigue viva** (carrera legítima: dos pestañas, app móvil con reintento) |
| El anterior fuera de la gracia, o uno más antiguo | 401 y **se revoca la sesión entera** (`revoked_reason = refresh_reuse`, posible robo) |
| Sesión inexistente o revocada | 401 |

Con `refresh_superseded` el cliente no debe cerrar sesión: descarta esa respuesta y usa el refresh que recibió la
otra petición (o reintenta con su último `refresh_token` guardado). `AUTH_REFRESH_REUSE_GRACE=0` desactiva la
gracia (toda reutilización revoca). Aun así conviene serializar los refresh en el cliente.
Pruebas: `SesionesDispositivosTest` y, con procesos reales, `tests/Concurrency/RefreshRotadoConcurrenteTest.php`.

## Lista negra de JWT (store propio)

Los tokens revocados (logout, refresh rotado, `revokeAllTokens`) se guardan en la lista negra de `jwt-auth`. Vive en
un **store de caché propio** (`jwt.blacklist_store`, `App\Auth\JwtBlacklistStorage`), no en la caché general: un
`php artisan cache:clear` o un `Cache::flush()` ya no la vacía ni resucita tokens revocados.

- Con `CACHE_STORE=redis` el defecto es el store `jwt_blacklist` (`config/cache.php`) sobre la conexión Redis `jwt`
  (BD `REDIS_JWT_DB`, 3 por defecto; `config/database.php`). Otro store: `JWT_BLACKLIST_STORE=<store>`.
- Sin Redis (`database`, `file`, `array`) el defecto es el store por defecto (comportamiento anterior).
- Redis del compose usa `allkeys-lru`: bajo presión de memoria puede expulsar claves. En producción, para esta BD
  usa una instancia con `noeviction` o `volatile-lru` (las claves de la lista negra llevan TTL).
- Las sesiones por dispositivo (`user_sessions`, en BD) siguen revocando por `sid` aunque la lista negra se perdiera.
- Prueba: `tests/Feature/Auth/ListaNegraStorePropioTest.php`.

## 2FA TOTP (opcional por usuario)

- Reutiliza las columnas cifradas del back-office (`app_authentication_secret`, `app_authentication_recovery_codes`):
  un único autenticador por persona. Librería: `pragmarx/google2fa` (ya la trae Filament).
- Activar: `POST /user/2fa/setup` (secreto + `otpauth_url`, pendiente 10 min en caché cifrada) ->
  `POST /user/2fa/confirm {code}` activa y devuelve 8 códigos de recuperación en claro (única vez; en BD, hasheados).
  `GET /user/2fa` da el estado. `POST /user/2fa/recovery-codes` y `DELETE /user/2fa` exigen `password` + `code`.
- Login con 2FA: `POST /auth/login` responde `data.two_factor_required = true` y `data.challenge_token`
  (opaco, 5 min, no es un JWT: no autentica nada) sin tokens. `POST /auth/2fa/verify {challenge_token, code}` emite
  los tokens y abre la sesión. `code` es un TOTP de 6 dígitos o un código de recuperación.
- Seguridad: cada TOTP vale una sola vez (anti-repetición en caché), el código de recuperación se consume con
  bloqueo de fila, el desafío se consume atómicamente y se agota tras `AUTH_2FA_MAX_ATTEMPTS`; además hay un
  límite por usuario (`AUTH_2FA_MAX_ATTEMPTS` fallos en `AUTH_2FA_LOCKOUT_MINUTES` -> 401 `account_locked`).
  Errores: 401 `two_factor_invalid` (mensaje genérico).
- `AUTH_2FA_ENABLED=false` apaga la función (el login ignora el secreto).

## Verificación de correo

- `POST /auth/register` envía `VerifyEmailNotification` (cola, plantilla `email_verification`). El enlace apunta
  a `AUTH_EMAIL_VERIFICATION_URL?token=...`; el frontend llama a `POST /auth/email/verify {token}` (público).
- Token sin estado `{id}.{caducidad}.{hmac}`: el HMAC cubre id, correo y caducidad con la clave de la app; cambiar el
  correo lo invalida. Inválido/caducado: 422 `token_invalid`. Repetir es inofensivo.
- Cambiar el correo de un usuario (`PUT /users/{id}` con `users.update`, `UserService::update`) anula
  `email_verified_at` y reenvía el enlace (`AUTH_EMAIL_VERIFICATION_ON_CHANGE`, por defecto sí).
- `POST /auth/email/resend` (auth) con límite `AUTH_EMAIL_VERIFICATION_RESEND_PER_HOUR` por usuario (429 `rate_limited`).
- Exigirlo en una ruta: `->middleware(['auth:api', 'verified'])` -> 403 `email_not_verified`.
  `AUTH_EMAIL_VERIFICATION_ENFORCE=false` lo desactiva en bloque.

## Versión mínima de app

`EnsureMinimumAppVersion` (alias `app-version`, ya en el grupo `api`) lee `X-App-Version` y `X-App-Platform`.
Con `APP_MIN_VERSION_ENABLED=true` y una mínima por plataforma (`APP_MIN_VERSION_IOS|ANDROID|WEB`, `APP_MIN_VERSION`
como comodín) responde 426 `app_update_required` (`errors.minimum_version`). Salta salud, webhooks entrantes y docs
(`config/app-versions.php` `except`). Sin cabecera pasa, salvo `APP_MIN_VERSION_REQUIRE_HEADER=true`.

## Pruebas

`tests/Feature/Auth/SesionesDispositivosTest.php`, `DosFactoresTest.php`, `VerificacionCorreoTest.php`,
`VersionMinimaAppTest.php` y la de concurrencia citada. Incluyen denegados y aislamiento entre usuarios de distintos tenants.
