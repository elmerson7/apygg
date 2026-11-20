# Tests de concurrencia

Carreras reales con procesos PHP hijos (`Tests\Support\ConcurrentRunner`).
Los datos quedan comprometidos: usa datos propios y bórralos en `afterEach`.
`make test-concurrency`. Ver `docs/testing.md`.

Cobros (`CobrosConcurrentesTest`, `FacturacionConcurrenteTest`): cobro doble (renovaciones + pago del cliente o manual),
webhook duplicado y procesado por varios workers, webhook vs respuesta síncrona (mismo y distinto PaymentIntent),
cancelación vs renovación, numeración por alcance y límites de plan. La pasarela falsa vive en la memoria de cada proceso:
suma sus llamadas entre procesos. Qué carrera cubre cada prueba y con cuántos procesos: cabecera del archivo.
