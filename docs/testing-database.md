# Base de datos de testing aislada

Los tests **nunca** deben correr contra la base de datos de desarrollo: una
migración fallida o un `migrate:fresh` mal lanzado borraría el trabajo del día.
El boilerplate usa una BD separada, derivada automáticamente del nombre de la
BD de desarrollo.

## Cómo funciona

| Pieza | Rol |
|---|---|
| `create_testdb.sh` | Crea `<DB_DATABASE>_test` leyendo `.env` y `compose.env`. Nada hardcodeado. |
| `Tests\TestCase::createApplication()` | Cambia la conexión a `<DB_DATABASE>_test` antes de arrancar la app. |
| `phpunit.xml` → `DB_TEST_ISOLATION` | Interruptor. En `false`, los tests corren contra la BD de la conexión por defecto. |

Si `DB_DATABASE=apygg`, la BD de tests es `apygg_test`. Si duplicas el
boilerplate y pones `DB_DATABASE=miapp`, pasa a ser `miapp_test` sin tocar
código.

## Primera vez

```bash
make testdb-migrate
```

Esto crea la BD, corre `migrate:fresh` y ejecuta los seeders base sobre ella.

Para tests en paralelo, cada proceso necesita su propia base:

```bash
make testdb-parallel PROCESSES=4   # crea apygg_test_1 … apygg_test_4
make test-parallel PROCESSES=4
```

`TestCase` resuelve el nombre con el token de proceso
(`ParallelTesting::token()`). Si todos compartieran una base, se pisarían los
datos y los tests fallarían de forma intermitente.

## Uso diario

```bash
make test           # toda la suite
make test-unit      # solo tests/Unit
make test-feature   # solo tests/Feature
make test-filter filter='login'
make test-coverage
```

Si solo necesitas crear la BD sin migrar:

```bash
make testdb
```

Guía de testing completa: [testing.md](testing.md).

## Estrategia de datos

Hay dos enfoques y conviene elegir uno conscientemente:

**A) `RefreshDatabase`** — cada test migra desde cero. Es el más seguro y el más
lento. Útil mientras el esquema cambia mucho.

**B) `DatabaseTransactions` + seed único** — los datos base se siembran **una
vez** con `make testdb-migrate` y cada test abre una transacción que se revierte
al terminar. Mucho más rápido en suites grandes. Requiere recordar volver a
correr `make testdb-migrate` cuando se añade una migración.

El `TestCase` del boilerplate no impone ninguno: añade el trait que prefieras en
tus tests o en `tests/Pest.php`.

## Cuando añades una migración

```bash
make testdb-migrate   # recrea el esquema de la BD de tests
```

## Notas

- La BD de tests se crea con el mismo `OWNER` que la de desarrollo, por lo que
  no hace falta un usuario adicional en PostgreSQL.
- `create_testdb.sh` es idempotente: si la BD ya existe, lo informa y continúa.
- El script requiere que los contenedores estén levantados (`make up`), porque
  ejecuta `psql` y `artisan` dentro de ellos.
- Variables de entorno de tests (cola `sync`, mailer `array`, Scout `null`) se
  definen en `phpunit.xml`, no en `.env`.
