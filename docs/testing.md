# Testing

El proyecto usa **Pest 4** sobre PHPUnit. Todo se ejecuta dentro de Docker vía
`make`: no hay PHP ni base de datos en el host.

## Tipos de test disponibles

| Tipo | Dónde | Qué comprueba | Comando |
|---|---|---|---|
| **Unitarios** | `tests/Unit/` | Una clase aislada: servicios, modelos, helpers | `make test-unit` |
| **Integración (Feature)** | `tests/Feature/` | Un endpoint de punta a punta: ruta, middleware, validación, BD | `make test-feature` |
| **Arquitectura** | `tests/Arch/` | Reglas estructurales del código, no comportamiento | `make test-arch` |
| **Contrato** | `tests/Contract/` | Respuestas reales validadas contra `docs/openapi.json` con `assertMatchesOpenApi()` (ver `docs/api-contract.md`) | `make test-contract` |
| **Concurrencia** | `tests/Concurrency/` | Carreras reales con varios procesos PHP a la vez | `make test-concurrency` |
| **E2E** | `tests/E2E/` | El recorrido base por HTTP real contra el stack levantado (Octane, Horizon, Mailpit, S3) | `make test-e2e` |
| **Cobertura** | — | Qué porcentaje del código ejecutan los tests | `make test-coverage` |
| **Mutación** | — | Si los tests **detectarían** un cambio en el código | `make test-mutate` |
| **Paralelo** | — | La misma suite repartida en N procesos | `make test-parallel` |

## Suites y cuándo usar cada una

`phpunit.xml` define las suites. **`make test` corre solo `Unit` + `Feature` +
`Arch`** (`defaultTestSuite`); el resto se pide a propósito.

| Suite | Úsala cuando... |
|---|---|
| `Unit` | pruebas una clase aislada |
| `Feature` | pruebas un endpoint completo: ruta, middleware, validación, BD |
| `Arch` | quieres fijar una regla estructural (qué puede usar qué) |
| `Contract` | quieres que un cambio accidental en la forma de una respuesta falle |
| `Concurrency` | el fallo solo aparece con peticiones simultáneas (únicos, contadores, idempotencia) |
| `E2E` | necesitas el flujo completo contra la API levantada de verdad (servidor, cola y servicios reales) |

Los tests de las suites nuevas usan `Tests\TestCase` igual que Feature/Unit
(`tests/Pest.php`). Registrar otra suite es una línea más en `<testsuites>`.

## Puesta en marcha

```bash
make testdb-migrate      # crea y migra la BD de tests (solo la primera vez)
make test                # toda la suite
```

Vuelve a ejecutar `make testdb-migrate` cada vez que añadas una migración.

## Comandos

```bash
make test                              # suite por defecto: Unit + Feature + Arch
make test-all                          # todas las suites sin servicios extra
make test-contract                     # solo Contract
make test-concurrency                  # solo Concurrency (procesos reales)
make test-e2e                          # recorrido E2E contra el stack levantado (ver más abajo)
make test-changed                      # solo tests con cambios sin commitear (pest --dirty)
make ci                                # como el CI: check + cobertura mínima
make test-unit                         # solo tests/Unit
make test-feature                      # solo tests/Feature
make test-filter filter='login'        # por nombre
make test-arch                          # solo reglas de arquitectura
make test-watch                         # re-ejecuta al guardar

make test-coverage                      # informe de cobertura
make test-coverage-min                  # falla si baja del mínimo (COVERAGE_MIN)

make testdb-parallel PROCESSES=4        # prepara una BD por proceso
make test-parallel PROCESSES=4          # ejecuta en paralelo

make test-mutate                        # mutation testing (lento)
```

---

## 1. Tests unitarios

Una clase, sin HTTP. Son la mayoría y deben ser rápidos.

```php
use App\Services\LLM\StructuredOutput;

test('extrae JSON dentro de un bloque markdown', function () {
    expect(StructuredOutput::extract("```json\n{\"ok\": true}\n```"))
        ->toBe(['ok' => true]);
});
```

Si la clase toca la base de datos, añade el trait:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
```

Para servicios con dependencias, resuélvelos por el contenedor —nunca con
`new`— para que lleguen sus dependencias inyectadas:

```php
$this->userService = app(UserService::class);
```

## 2. Tests de integración (Feature)

Atraviesan el stack completo: ruta, middleware, autenticación, validación,
servicio y base de datos. Son los que de verdad demuestran que un endpoint
funciona.

```php
test('puede crear un usuario con permiso users.create', function () {
    $admin = User::factory()->admin()->create();
    $token = JWTAuth::fromUser($admin);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/users', [...]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'email']]);

    $this->assertDatabaseHas('users', ['email' => 'nuevo@ejemplo.com']);
});
```

**Cubre siempre el camino denegado**, no solo el feliz: que un usuario sin el
permiso reciba 403 es tan importante como que el que lo tiene reciba 201.

## 3. Tests de arquitectura

Comprueban reglas estructurales sin ejecutar nada. Son rápidos y atrapan lo que
una revisión humana deja pasar cuando el proyecto crece.

```php
arch('no quedan restos de depuración')
    ->expect(['dd', 'dump', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('los modelos no conocen la capa HTTP')
    ->expect('App\Models')
    ->not->toUse(['App\Http\Controllers', 'App\Http\Requests']);
```

Las reglas vigentes están en `tests/Arch/ArchTest.php`. Cuando una excepción
sea legítima, documéntala con `->ignoring(...)` y un comentario que explique por
qué —no la borres—.

## 4. Cobertura

```bash
make test-coverage              # informe por archivo
make test-coverage-min          # falla por debajo de COVERAGE_MIN (make/test.mk)
make test-coverage-min MIN=80   # con otro umbral puntual
```

Usa **pcov**, que va instalado pero desactivado (`pcov.enabled=0` en el
`php.ini` del contenedor): tenerlo activo instrumenta todo el código y ralentiza
la aplicación. Los targets del Makefile lo encienden solo para esa ejecución.

La cobertura mide qué líneas se ejecutan, **no si están bien comprobadas**. Un
80% con aserciones flojas vale menos que un 50% con aserciones que muerden. Úsala
para encontrar lo que nadie ha tocado, no como objetivo.

El mínimo vigente es `COVERAGE_MIN` en `make/test.mk` y `--min` en
`.github/workflows/ci.yml` (misma cifra en ambos): la cobertura medida redondeada
hacia abajo a múltiplo de 5. Súbelo cuando la cobertura real suba; nunca lo bajes
para que pase un cambio.

## 5. Mutation testing

Responde a la pregunta que la cobertura no puede: *si rompo esta línea a
propósito, ¿lo detecta algún test?*

```bash
make test-mutate
docker exec <app> php -d pcov.enabled=1 ./vendor/bin/pest \
    --mutate --class="App\Services\MiServicio" --covered-only
```

Pest cambia el código (invierte condiciones, cambia operadores, elimina
llamadas) y ejecuta los tests. Si siguen pasando, la mutación queda **UNTESTED**:
esa línea está cubierta pero no comprobada.

Es lento —minutos por clase—, así que úsalo dirigido a la lógica crítica, no
sobre toda la aplicación. Pásale solo los archivos de test de esa clase: con la suite
entera tarda mucho más y gasta más memoria. No combines `--class` con `--everything`:
`--everything` anula el filtro y muta toda `app/`.

Trampas medidas (25-nov-2026):

- **Middlewares y comandos salen siempre "UNCOVERED"**: `phpunit.xml` excluye
  `app/Http/Middleware`, `app/Console` y `app/Exceptions` de la cobertura, y Pest decide qué
  test mata cada mutación con esa cobertura. Para medir un middleware, usa una copia
  temporal sin la exclusión (no la commitees):
  `sed '/app\/Http\/Middleware/d' phpunit.xml > phpunit.mutacion.xml` y añade
  `-c phpunit.mutacion.xml` al comando.
- **Las constantes y los valores iniciales de propiedades** salen como UNCOVERED aunque un
  test los compruebe: la cobertura no registra esas líneas.
- **La suite `Concurrency`** corre en procesos hijos: sus comprobaciones no cuentan para la
  mutación. Las ramas que solo se dan en una carrera (reintentos, pausas) quedan UNTESTED.
- Una mutación que quita un `(int)`/`(bool)` que PHP ya aplica por el tipo de retorno o del
  parámetro es **equivalente**: no hay test que la mate. Anótala, no la persigas.

## 6. Paralelo

```bash
make testdb-parallel PROCESSES=4   # una vez
make test-parallel PROCESSES=4
```

Cada proceso usa **su propia base** (`apygg_test_1`, `_2`…): si comparten una,
se pisan los datos y los tests fallan de forma intermitente, que es la peor
forma de fallar. `TestCase` resuelve el nombre con el token de proceso.

Referencia (23-nov-2026): la suite tiene ~2.260 tests; con 4 procesos tarda varias veces menos que
en serie. Mídelo en tu máquina: `make test-parallel` frente a `make test`.

---

## Concurrencia con procesos reales

Un test secuencial no ve las carreras: dos peticiones que comprueban `unique` a la
vez y luego insertan. `Tests\Support\ConcurrentRunner` lanza N procesos PHP
(`proc_open`) con el patrón barrera: cada hijo arranca la app, avisa de que está
listo y espera un archivo "go"; el padre lo crea cuando todos están listos y todos
ejecutan la misma operación a la vez.

```php
$results = ConcurrentRunner::run(2, function (int $i) use ($email) {   // sin $this
    $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle(/* Request */);

    return ['status' => $response->getStatusCode()];
});
// $results[$i] = ['ok' => bool, 'result' => <lo que devolvió la closure>, 'error' => ?string]
```

Reglas:

- Los hijos usan la **misma BD de tests** que el padre, y lo que escriben queda
  **comprometido**: `RefreshDatabase`/`DatabaseTransactions` no cubren a otros
  procesos. Usa datos propios (p. ej. emails `carrera-*@example.com`) y bórralos en
  `afterEach`.
- La closure se serializa (`laravel/serializable-closure`): captura solo valores
  escalares/arrays, no uses `$this` ni modelos con relaciones.
- Cada hijo tiene su propia caché `array` y su propio rate limiter en memoria.
- Un hijo que muere o no llega a la barrera en el tiempo límite hace fallar el test
  con su error.

Primer caso real: `tests/Concurrency/RegistroConcurrenteTest.php` (dos registros
con el mismo email). Encontró una carrera: la validación `unique` pasaba en ambos
y el segundo `INSERT` daba **500** con el SQL en el título. Ahora el handler global
(`bootstrap/app.php`) convierte `UniqueConstraintViolationException` en **422** con
el formato de validación y sin exponer SQL ni valor.

## Pruebas E2E

`tests/E2E/` habla por **HTTP real** (`Http::` de Laravel, nunca `$this->get()` del kernel) con un stack
levantado: Octane de verdad, Horizon consumiendo la cola, Mailpit y S3. Es la única suite que prueba que las
piezas funcionan *juntas* (el correo sale por la cola real, el webhook llega a un receptor real, el cobro
simulado activa la suscripción...). Es lenta y necesita infraestructura, por eso va aparte y **se omite** si
no hay stack (`E2E_BASE_URL` sin definir).

Cada recorrido es **independiente y repetible**: crea sus propios usuarios y empresas con datos únicos por
ejecución (`e2e-<alias>-<run>@example.test`), no depende de los demás ni exige limpiar nada. Dentro de un
recorrido los pasos van en orden y dependen unos de otros (`->depends()`): si uno falla, los siguientes se omiten.

| Archivo | Recorrido |
|---|---|
| `RecorridoBaseTest.php` | Salud, registro, correo de bienvenida (Mailpit), login/refresh rotado, archivo privado, webhook hacia un receptor real, logout. El webhook cuelga de una empresa: con multiempresa un evento sin tenant (el login) **no** se entrega (se comprueba que no llega nada); la entrega con firma HMAC se verifica en un stack sin multiempresa (`make test-e2e` sobre el stack de desarrollo) |
| `EmpresaEquipoTest.php` | Alta de empresa A y B -> invitación con `Idempotency-Key` (el reintento no duplica) -> correo por la cola con el token -> aceptación (otro email 403, repetir 409) -> miembros -> aislamiento A/B por HTTP -> exportación de miembros por la cola |
| `FacturacionTest.php` | Catálogo público -> plan con prueba -> método de pago -> fin de prueba y primer cobro (`billing:renew`) -> PDF y aviso en la bandeja -> renovación (cron repetido sin duplicar) -> rechazo del banco (`past_due`, escritura con `X-Subscription-Warning`) -> pasada la gracia 402 `payment_required` -> otra tarjeta y pago con `Idempotency-Key` -> desbloqueo -> baja inmediata 402 `subscription_inactive` -> recontratar |
| `ArchivosTest.php` | Subida directa presignada al S3 local (intención con `Idempotency-Key`, `PUT` al bucket, completar) -> descarga, URL temporal y cuota -> tipo declarado falso rechazado -> aislamiento A/B |
| `SesionesDosFactoresTest.php` | Dos sesiones, revocar una (su access muere al instante), 2FA con TOTP real (código malo, bueno, desafío de un solo uso, recuperación de un solo uso), desactivar, cambio de contraseña que invalida todo |

### Correr la suite completa: `make e2e`

```bash
make init NAME=miapp     # una vez: crea .env y compose.env
make e2e                 # levanta el stack efímero, corre tests/E2E y lo baja con down -v
make e2e ARGS="--filter=Facturacion"   # argumentos de Pest
API_PREFIX=v1 make e2e   # la misma suite con la API bajo /v1 (el stack y los helpers leen API_PREFIX)
```

`make e2e` (`scripts/e2e-stack.sh`) usa **su propio** `docker-compose.e2e.yml` y no toca el stack de desarrollo:

- Nombre de proyecto único (`E2E_PROJECT`, por defecto `apygg-e2e-<carpeta>`), red y volúmenes propios y **sin
  puertos publicados** en el host (el runner de Pest entra por la red del stack): no choca con nada.
- Mínimo y acotado: app (Octane, 2 workers, 384 MB), Horizon (768 MB: master, supervisores y workers son procesos PHP), Postgres 18
  (256 MB), Redis (64 MB), Mailpit (48 MB), S3 local RustFS (192 MB) y el runner (192 MB): **~1,9 GB** en total. Sin Reverb ni
  scheduler (los recorridos lanzan el cron a mano).
- Sin red externa: pasarela de pago `fake`, correo a Mailpit, archivos en el S3 local.
- Al terminar (también si falla o se interrumpe) hace `down -v --remove-orphans`: no deja contenedores,
  volúmenes ni redes. `E2E_KEEP=1` lo deja arriba para depurar (`make e2e-up`, `make e2e-down`,
  `scripts/e2e-stack.sh test --filter=...`).
- En un servidor compartido, `E2E_LOCK=<fichero>` hace que espere turno con `flock` antes de levantar nada, y
  `E2E_REUSE_IMAGES=1` reutiliza la imagen local `apygg-app:latest` en vez de construirla.

Lo que el stack E2E cambia respecto al `.env` (variables de `x-e2e-env` en `docker-compose.e2e.yml`; **solo
para este stack, nunca en producción**): `MULTI_TENANCY_ENABLED` y `TENANT_SIGNUP_ENABLED` (las empresas se
dan de alta solas), `PLAN_LIMITS_ENABLED`, `PAYMENTS_MODE=fake`, `WEBHOOKS_ALLOW_HTTP` y
`WEBHOOKS_ALLOWED_HOSTS` (el receptor de la prueba es http y está en la red privada: el filtro SSRF lo
bloquearía) y los topes de peticiones por minuto (`RATE_LIMIT_*_MAX`, `AUTH_LOGIN_MAX_ATTEMPTS*`; en
producción siguen los de `config/rate-limiting.php`).

### El reloj y el cron en los recorridos de facturación

No se espera a que pase el tiempo: el runner del stack puede **adelantar el reloj de una empresa** y **lanzar el
cron**, y son los únicos pasos que tocan el stack por dentro (ambos solo de pruebas, sin endpoint nuevo en la API):

- `e2eViajar($tenantId, $dias)` retrocede esos días las fechas de la suscripción, facturas y cobros de ESA
  empresa en la BD del stack (fin de prueba, periodo, reintentos, emisión), de modo que "ahora" queda días después.
- `e2eArtisan('billing:renew')` ejecuta el comando contra el stack (devolviendo a cola/caché/correo reales lo que
  `phpunit.xml` fuerza para los tests).

Necesitan `E2E_ARTISAN=1` (lo pone el runner del stack temporal; el DB se toma de `E2E_DB_DATABASE`). Contra un stack
remoto esos pasos se omiten. Los recorridos que necesitan multiempresa con alta libre se omiten sin
`E2E_MULTITENANT=1`.

### Variables que lee la suite

| Variable | Qué es |
|---|---|
| `E2E_BASE_URL` | API bajo prueba (obligatoria; sin ella toda la suite se omite) |
| `E2E_MULTITENANT` | `1` si el stack tiene multiempresa y alta libre; sin ella se omiten los recorridos de empresa, facturación y archivos |
| `E2E_ARTISAN`, `E2E_DB_DATABASE` | `1` si el runner está dentro del stack (BD y artisan); necesarios para adelantar el reloj y lanzar `billing:renew` |
| `E2E_MAIL_URL` | API de Mailpit (`http://mailpit:8025`); sin ella se omiten los pasos de correo |
| `E2E_S3_ENDPOINT`, `E2E_S3_KEY`, `E2E_S3_SECRET`, `E2E_S3_PRIVATE_BUCKET` | S3 para la subida presignada y el bucket privado |
| `E2E_WEBHOOK_RECEIVER_URL` | URL del receptor de webhooks vista **desde el stack** (por defecto `http://app:9199`; en `make e2e`, el contenedor del runner: `http://<E2E_PROJECT>-runner:9199`) |
| `E2E_APP_TITLE` | si se define, la ficha de `/` debe llevar exactamente ese título |

### Recorridos de navegador: `make frontend-e2e`

El cliente de pruebas (`frontend-demo/`) trae sus propios recorridos **Playwright** (escritorio y móvil) que reutilizan este
mismo stack efímero (`docker-compose.e2e.yml` + `scripts/e2e-stack.sh`) y lo bajan con `down -v`. Cómo corre, qué recorre y
cómo añadir uno: [`docs/frontend-demo.md`](frontend-demo.md).

### Contra el stack de desarrollo: `make test-e2e`

`make setup` + `make test-e2e` sigue existiendo: corre Pest dentro del contenedor `app` del proyecto contra
`http://localhost:8000`. Solo ejecuta lo que ese stack permita (multiempresa y S3 si su `.env` los activa; el
reloj y el cron no están disponibles ahí). Para que el recorrido base sea completo, el `.env` necesita
`FILE_DISK_PUBLIC=s3`, `FILE_DISK_PRIVATE=s3_private`, `WEBHOOKS_ALLOW_HTTP=true` y `WEBHOOKS_ALLOWED_HOSTS=app`
(se leen al crear los contenedores).

### Escribir un recorrido nuevo

1. Archivo `tests/E2E/<Tema>Test.php` con una constante de nombre de recorrido (`const E2E_XX = 'tema'`) y
   `e2e(E2E_XX)` como estado compartido entre pasos.
2. Usuarios con `e2eUsuario()`, empresas con `e2eEmpresa()`, clientes HTTP con `e2eApi($token, $slugEmpresa)`,
   correo con `e2eCorreo()`, esperas acotadas con `e2eEsperar()` (nunca `sleep` a ciegas). Los helpers viven en
   `tests/E2E/helpers.php`.
3. Datos únicos por ejecución (el `run` de `e2e()`), sin depender de que la BD esté vacía.
4. Cubre siempre el camino denegado y el aislamiento A/B, no solo el feliz.

Notas:

- Los endpoints de autenticación limitan por minuto y por IP (5 por defecto): `e2ePostAuth` espera el reinicio
  de la ventana ante un 429; el stack de `make e2e` sube el tope, así que no se espera.
- Cada ejecución crea usuarios y empresas nuevos que quedan en la BD del stack; en `make e2e` desaparecen con el
  `down -v`. En un stack permanente, `make mail-clear` vacía Mailpit.
- En CI el job `e2e` ejecuta `make e2e` en cada PR, en push a `main`/`develop` y a mano (`workflow_dispatch`), y
  **bloquea** `ci-success` (el check obligatorio de `main`): un recorrido roto impide unir el PR. No hay
  reintentos automáticos: un E2E intermitente se arregla. Ver `.github/workflows/ci.yml`.

## La base de datos de tests

Los tests **nunca** tocan la base de desarrollo. `TestCase::createApplication()`
cambia la conexión a `<DB_DATABASE>_test`, que crea `create_testdb.sh`.
Detalle completo en [testing-database.md](testing-database.md).

El interruptor `DB_TEST_ISOLATION` (en `phpunit.xml`) permite desactivarlo.

## Lo que ofrece `TestCase`

```php
$this->createUser();                  // usuario normal
$this->createAdmin();                 // usuario con rol admin
$this->actingAsUser($user);           // autenticar en el guard api
$this->loginAs($user);                // devuelve un JWT

$this->assertApiSuccess($response);       // envelope de éxito
$this->assertApiError($response, 422);    // envelope RFC 7807
$this->assertPermissionDenied($response); // 403
$this->assertUnauthorized($response);     // 401
```

Además, antes de cada test:

- se siembran roles y permisos base (`RoleSeeder`, `PermissionSeeder`);
- **se limpia la caché**. Es importante: `RefreshDatabase` revierte la base pero
  no Redis, así que sin limpiarla un test puede leer lo que cacheó otro y, con
  los IDs auto-incrementales reiniciados, recibir el registro equivocado.

El entorno de test usa además cola `sync`, mailer `array`, Scout `null` y caché
`array` (ver `phpunit.xml`), de modo que ningún test envía correos de verdad ni
depende de un worker.

## Convenciones

- **Nombres en español y en tercera persona**, describiendo la conducta:
  `test('rechaza una firma que no corresponde al cuerpo', ...)`. El nombre debe
  leerse como la especificación de lo que hace el sistema.
- **Un comportamiento por test.** Si el nombre necesita una "y", probablemente
  son dos.
- **Comprueba el contrato, no la implementación.** Un test que se rompe al
  renombrar un método privado es ruido.
- **Los tests de los casos límite valen más que los del camino feliz**: entrada
  vacía, permiso ausente, proveedor caído, valor nulo.
- La estructura de `tests/` espeja la de `app/`.
- Usa factories, no filas creadas a mano, salvo cuando el valor exacto sea el
  objeto del test.

## Trampas que ya nos han mordido

- **Parámetros sin tipo en acciones.** `store($request)` no recibe el Form
  Request (Laravel solo inyecta parámetros con tipo de clase) y la ruta da 500:
  tipa siempre (`store(StoreXRequest $request)`). Los scopes del `Model` base
  están sin tipar: no estreches su firma en los hijos.
- **Los IDs son enteros**, no UUID (ver [id-strategy.md](id-strategy.md)). Un
  test que use `Str::uuid()` como id fallará contra columnas `bigint`.
- **PostgreSQL devuelve los `bigint` como string** por PDO. Sin un cast a
  `integer` en el modelo, `expect($log->user_id)->toBe($user->id)` falla por
  tipo. Usa `toBe` solo cuando el tipo esté garantizado.
- **Los roles sembrados ya existen.** `Role::create(['name' => 'editor'])` en un
  test choca con `RoleSeeder`; usa `firstOrCreate`.

## Datos de prueba únicos

Las factories con columnas únicas (`UserFactory` email e `identity_document`,
`SettingsFactory` key, `RoleFactory` name) añaden un sufijo aleatorio en lugar de
`fake()->unique()`: ese se reinicia en cada test y solo conoce lo que generó él
mismo, de modo que chocaba de forma intermitente con filas ya existentes. Si
añades una factory con una columna única, haz lo mismo.

## En CI

`.github/workflows/ci.yml`:

| Job | Qué corre | Cuándo |
|---|---|---|
| `lint` | `pint --test` + `phpstan` (= `make check`) | siempre |
| `test` | con Postgres 18 y Redis 7 como servicios: suite por defecto (Unit + Feature + Arch) con cobertura mínima (`--min`, igual a `COVERAGE_MIN` de `make/test.mk`) y después `Contract` + `Concurrency` (esta última compromete datos en la BD de tests, por eso va al final) | siempre |
| `security` | `composer audit` | siempre |
| `e2e` | `make e2e`: stack efímero de `docker-compose.e2e.yml` (app con Octane, Horizon, Postgres, Redis, Mailpit, S3), `tests/E2E` y `down -v` | siempre; bloquea `ci-success` |

Equivalente local: `make ci` (check + cobertura mínima) y `make test-all` (todas las suites sin servicios extra).

## Datos masivos

`php artisan seed:load` (`make seed-load`) genera miles de empresas con facturas, pagos y archivos para pruebas de volumen y de carga; la suite lo prueba en pequeño (`tests/Feature/SeedLoad/`). Ver [seed-load.md](seed-load.md).

Pruebas de carga con k6 (humo, carga, estrés, pico, resistencia) sobre esos datos: `make load-smoke` y compañía; ver [load-testing.md](load-testing.md).

## Referencias

- [testing-database.md](testing-database.md) → la BD aislada en detalle
- `tests/TestCase.php` → helpers y preparación
- `tests/Arch/ArchTest.php` → reglas de arquitectura
- `phpunit.xml` → entorno de test
