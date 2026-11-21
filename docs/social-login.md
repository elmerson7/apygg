# Login social (módulo opcional `social-login`)

OAuth2/OIDC para apps móviles y SPA con **Laravel Socialite** (`laravel/socialite`, versión fija en `composer.json`).
API-only: la API no hace redirecciones ni guarda sesión web. La app hace el flujo con el proveedor (código + PKCE) y
**canjea** lo que obtiene en `POST /auth/social/{provider}` por los JWT de APYGG. Apagado por defecto
(`SOCIAL_LOGIN_ENABLED=false`: responde 404 `social_provider_unavailable`).

## Flujo

1. La app genera `nonce` y PKCE (`code_verifier`/`code_challenge`) y abre el flujo del proveedor (SDK nativo o navegador).
2. Con el código y el verifier obtiene del proveedor un **`id_token`** (OIDC: Google, Apple, Microsoft...) o un
   **`access_token`** (OAuth2 sin OIDC: GitHub). El canje código→token lo hace la app (cliente público); la API nunca ve el verifier.
3. `POST /auth/social/{provider}` con `{ "id_token": "...", "nonce": "..." }` o `{ "access_token": "..." }`.
4. Respuesta igual que `POST /auth/login`: `access_token` + `refresh_token`, o, con 2FA activo,
   `two_factor_required` + `challenge_token` (se completa en `POST /auth/2fa/verify`).

Errores (todos con `code` estable): 401 `social_login_invalid` (genérico: no dice si falló firma, audiencia o caducidad),
403 `social_email_unverified`, 403 `social_account_not_found`, 403 (cuenta bloqueada), 404 `social_provider_unavailable`,
409 `social_account_conflict`, 429 `rate_limited`.

## Validación del proveedor

| Tipo | Qué se valida |
|---|---|
| `oidc` (Google) | Firma RS256 contra el JWKS del proveedor (clave por `kid`; HS256/`none` se rechazan), `iss` en la lista, `aud` ∈ `client_ids`, `exp` (margen `SOCIAL_LOGIN_LEEWAY`), `sub` presente, `nonce` y **un solo canje por id_token** (hasta su caducidad). El JWKS va en caché (`SOCIAL_LOGIN_JWKS_CACHE_MINUTES`); un `kid` desconocido vuelve a pedirlo como mucho una vez por minuto. |
| `socialite` (GitHub) | Antes de pedir el perfil, el proveedor confirma que el token es de **esta** aplicación (`check_token_url`): sin eso cualquier app que obtenga un token de la víctima podría entrar aquí. Perfil con `Socialite::buildProvider(...)->stateless()->userFromToken()`. |

`nonce`: si la app lo envía o el token lo trae, deben coincidir; `SOCIAL_LOGIN_REQUIRE_NONCE=true` lo exige siempre.

## Vincular y crear cuentas (lo delicado)

Tabla `social_accounts` (`provider`, `provider_user_id` único, `user_id`; un usuario, una cuenta por proveedor).

1. **Ya vinculada** → entra el usuario vinculado (el email del proveedor puede haber cambiado; no vuelve a vincular nada).
2. **Sin vincular** → solo por email **verificado por el proveedor** (`email_verified` del id_token; en GitHub el email de
   Socialite es el principal verificado). **Un email sin verificar nunca toma una cuenta ni crea una** (403 `social_email_unverified`).
3. **Pre-secuestro**: si la cuenta local existente nunca verificó su correo (alguien pudo registrarla sin ser su dueño y
   conocer la contraseña), al vincular se verifica el correo, **se cambia la contraseña por una aleatoria y se cierran
   todas sus sesiones**. El dueño real entra por el proveedor o restablece la contraseña por correo.
4. **Cuenta inexistente** → 403 `social_account_not_found`, salvo `SOCIAL_LOGIN_AUTO_REGISTER=true`: crea el usuario con
   `UserService::create` (rol por defecto, evento `UserCreated`), correo verificado y contraseña aleatoria inutilizable.
   Los usuarios borrados no se reactivan ni se duplican. La creación no pasa por consentimientos legales
   (`privacy`): si tu proyecto los exige, hazlo en la app tras el primer login.
5. Una cuenta que ya tiene OTRA identidad del mismo proveedor → 409 `social_account_conflict` (no se pisa).

Todo el login pasa por `AuthServiceInterface::authenticateVerified()`, la misma puerta que el login con contraseña:
**lista negra** (403), **2FA** (desafío), **sesión por dispositivo** (`sid`, revocación), evento `Login` y registro de
seguridad con `method=social:<proveedor>`. Dos primeros logins simultáneos no duplican: los únicos de BD hacen que el segundo reintente.

## Límites

`SOCIAL_LOGIN_MAX_FAILURES_PER_IP` canjes fallidos en `SOCIAL_LOGIN_FAILURE_WINDOW_MINUTES` → 429. Además aplica el
límite adaptativo de `auth/*`.

## Configuración (`config/social-login.php`)

```env
SOCIAL_LOGIN_ENABLED=true
SOCIAL_GOOGLE_CLIENT_IDS=web-id.apps.googleusercontent.com,ios-id...,android-id...   # los `aud` aceptados
SOCIAL_GITHUB_CLIENT_ID=...
SOCIAL_GITHUB_CLIENT_SECRET=...      # nunca al repositorio
```

Un proveedor sin `client_ids` (OIDC) o sin credenciales (Socialite) responde 404. **Otro proveedor**: añade una entrada en
`providers` (`type: oidc` con `issuers`/`jwks_uri`/`client_ids`; o `type: socialite` con `socialite_class` del paquete de
Socialite Providers, `client_id`, `client_secret`, `trust_email` solo si ese proveedor devuelve únicamente emails
verificados, y `check_token_url` si tiene un endpoint para validar la audiencia del token). La ruta `{provider}` es la clave de la entrada.

## Pruebas y retirada

`tests/Feature/Auth/SocialLogin/` (sin red: resolutor falso, RSA generado en el test y `Http::fake` para el JWKS).
`make module-remove name=social-login` quita código, ruta, bindings, env y tabla (`social_accounts`); después
`composer remove laravel/socialite`.
