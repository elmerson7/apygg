# Login sin contraseña por código de un solo uso (módulo opcional `otp-login`)

La persona pide un código a su correo (o por SMS) y lo canjea por los JWT. Apagado por defecto
(`OTP_LOGIN_ENABLED=false`: 404 `otp_login_unavailable`). Config en `config/otp-login.php`.

## Endpoints (públicos)

- `POST /auth/otp/request` `{ "email" }` (o `{ "phone": "+34600000000" }` con `OTP_LOGIN_SMS_ENABLED=true`) → siempre
  **202** con el mismo cuerpo (`expires_in`), exista o no la cuenta.
- `POST /auth/otp/verify` `{ "email" | "phone", "code" }` → como `POST /auth/login`: tokens, o con 2FA el desafío
  `two_factor_required` + `challenge_token` (se completa en `POST /auth/2fa/verify`).

## Seguridad

| Qué | Cómo |
|---|---|
| Código | Solo dígitos (`OTP_LOGIN_LENGTH`, mín. 6) con `random_int`; en BD **hasheado** (`Hash::make`), nunca en claro. El destino se guarda como HMAC (`destination_hash`), no como correo. |
| Caducidad y un solo uso | `OTP_LOGIN_TTL_MINUTES` (10). Se consume con bloqueo de fila; reutilizarlo da 401 `otp_invalid`. Pedir uno nuevo anula los anteriores del destino. |
| Intentos | `OTP_LOGIN_MAX_ATTEMPTS` (5) fallos con el mismo código y se anula, aunque luego se acierte. |
| Fuerza bruta | Fallos por destino (`..._VERIFY_FAILURES_PER_DESTINATION`) y por IP (`..._PER_IP`) en la ventana → 429 `rate_limited`, aunque se pidan códigos nuevos. |
| Envíos | Por destino (`OTP_LOGIN_SEND_PER_DESTINATION`) y por IP (`OTP_LOGIN_SEND_PER_IP`) en la ventana; cuentan **exista o no** el destino, así el 429 tampoco lo delata. |
| Sin enumeración | Misma respuesta y mismo coste de hash con cuenta o sin ella; sin cuenta (o bloqueada) no se crea fila ni se envía nada. `otp_invalid` es un 401 genérico para código malo, caducado, usado, agotado o destino inexistente. |
| Cola | El correo es una `Notification` en cola (`LoginCodeNotification`, plantilla pausable `login_code`); el SMS, `SendSmsJob` vía `SmsSenderInterface` con clave de idempotencia. El código viaja en claro en el payload de la cola mientras dura el envío (TTL corto): protege Redis como el resto de colas. |
| SMS | El teléfono (`user_profiles.phone`, E.164) debe identificar a UNA persona: si lo comparten dos usuarios no se envía a nadie. |
| Login | `AuthServiceInterface::authenticateVerified()`: lista negra (403), 2FA, sesión por dispositivo, evento `Login`, registro `method=otp:<canal>`. Recibir el código por correo marca el correo como verificado. |

`otp-login:clean` (diario 04:10) borra códigos usados o caducados tras `OTP_LOGIN_RETENTION_DAYS`.

## Pruebas y retirada

`tests/Feature/Auth/OtpLogin/`. `make module-remove name=otp-login` quita código, rutas, schedule, env, plantilla de idioma y la tabla `login_otps`.
