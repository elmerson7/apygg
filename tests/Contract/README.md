# Tests de contrato

Validan respuestas REALES de la API contra `docs/openapi.json` con `$this->assertMatchesOpenApi($response)`
(envelope, tipos, campos obligatorios y status documentados; errores con `code` y `request_id`).
Si fallan, un cliente se rompería: o la respuesta se salió del contrato, o hay que regenerarlo con `make openapi`.
Suite `Contract`: `make test-contract`. Ver `docs/api-contract.md` y `docs/testing.md`.
