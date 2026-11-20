# Tests E2E

Suite `E2E`: recorridos de la API por **HTTP real** (`Http::` de Laravel, nunca el kernel) contra un
stack levantado de verdad (Octane, Horizon, Mailpit, S3).

- `RecorridoBaseTest.php`: salud, registro, correo, login/refresh, archivo privado, webhook firmado, logout.
- `EmpresaEquipoTest.php`: alta de empresa, invitación por correo, aceptación, miembros, exportación y aislamiento A/B.
- `FacturacionTest.php`: plan con prueba, cobro simulado, renovación, impago, bloqueo 402, pago, desbloqueo y baja.
- `ArchivosTest.php`: subida directa presignada al S3, descarga, URL temporal, cuota y aislamiento.
- `SesionesDosFactoresTest.php`: sesiones, revocación, 2FA TOTP y recuperación, cambio de contraseña.
- `helpers.php`: helpers `e2e*()` compartidos (los carga `tests/Pest.php`).
- Se **omite** entera si `E2E_BASE_URL` no está definida, así `make test` no la necesita.
- Se corre con `make e2e` (stack efímero propio, se baja solo) o `make test-e2e` (contra el stack del
  proyecto). Cómo funciona, las variables `E2E_*` y cómo escribir un recorrido: `docs/testing.md`, sección
  "Pruebas E2E".

Cada recorrido crea datos únicos por ejecución (es repetible) y sus pasos van en orden y dependen unos de
otros (`->depends()`): si uno falla, los siguientes se omiten.
