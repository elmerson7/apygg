# Testing

El proyecto usa **Pest 4** sobre PHPUnit. Todo se ejecuta dentro de Docker vía
`make`: no hay PHP ni base de datos en el host.

## Tipos de test disponibles

| Tipo | Dónde | Qué comprueba | Comando |
|---|---|---|---|
| **Unitarios** | `tests/Unit/` | Una clase aislada: servicios, modelos, helpers | `make test-unit` |
| **Integración (Feature)** | `tests/Feature/` | Un endpoint de punta a punta: ruta, middleware, validación, BD | `make test-feature` |
| **Arquitectura** | `tests/Arch/` | Reglas estructurales del código, no comportamiento | `make test-arch` |
| **Contrato** | `tests/Contract/` | La forma pública de la API (envelope, tipos, códigos) | `make test-contract` |
| **Concurrencia** | `tests/Concurrency/` | Carreras reales con varios procesos PHP a la vez | `make test-concurrency` |
| **E2E** | `tests/E2E/` | Flujos de punta a punta (suite reservada, vacía) | `make test-e2e` |
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
| `E2E` | necesitas el flujo completo contra la API levantada de verdad |

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
make test-e2e                          # solo E2E (reservada)
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
sobre toda la aplicación.

## 6. Paralelo

```bash
make testdb-parallel PROCESSES=4   # una vez
make test-parallel PROCESSES=4
```

Cada proceso usa **su propia base** (`apygg_test_1`, `_2`…): si comparten una,
se pisan los datos y los tests fallan de forma intermitente, que es la peor
forma de fallar. `TestCase` resuelve el nombre con el token de proceso.

Referencia en esta base de código: 250 tests en ~41 s con 4 procesos frente a
~129 s en serie.

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

```yaml
- run: php artisan migrate --force        # sobre la BD de tests
- run: ./vendor/bin/pest --ci --coverage --min=<COVERAGE_MIN>
- run: ./vendor/bin/pint --test
- run: ./vendor/bin/phpstan analyse
```

`make check` (pint + phpstan) es obligatorio tras cualquier cambio PHP.

## Referencias

- [testing-database.md](testing-database.md) → la BD aislada en detalle
- `tests/TestCase.php` → helpers y preparación
- `tests/Arch/ArchTest.php` → reglas de arquitectura
- `phpunit.xml` → entorno de test
