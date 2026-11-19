# Tests E2E

Suite `E2E`: el recorrido base de la API por **HTTP real** (`Http::` de Laravel, nunca el
kernel) contra un stack levantado de verdad (Octane, Horizon, Mailpit, S3).

- `RecorridoBaseTest.php`: salud y ficha, registro, correo de bienvenida en Mailpit (por la
  cola), login/refresh/`me`, archivo privado (descarga autorizada, 401 y 403/404), webhook
  firmado hacia un receptor que abre el propio test, y logout.
- Se **omite** entera si `E2E_BASE_URL` no está definida, así `make test` no la necesita.
- Se corre con `make test-e2e` contra el stack del proyecto (`make setup`). Cómo levantarlo,
  las variables `E2E_*` y el `.env` que necesita (webhooks hacia la red de Compose, S3):
  `docs/testing.md`, sección "Pruebas E2E".

Los pasos van en orden y dependen unos de otros (`->depends()`): si uno falla, los siguientes
se omiten. Para un flujo nuevo, crea otro `*Test.php`; si reutilizas los helpers `e2e*()` de
`RecorridoBaseTest.php`, muévelos antes a `tests/Pest.php`.
