# Tests de concurrencia

Carreras reales con procesos PHP hijos (`Tests\Support\ConcurrentRunner`).
Los datos quedan comprometidos: usa datos propios y bórralos en `afterEach`.
`make test-concurrency`. Ver `docs/testing.md`.
