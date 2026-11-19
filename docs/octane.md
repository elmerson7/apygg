# Código seguro con Octane / FrankenPHP

Bajo Octane el proceso PHP **vive entre peticiones**: el contenedor, los singletons, los guards
y todo lo que cuelgue de una propiedad estática sobreviven a la petición que los creó. Un dato
de la petición A que se queda en memoria lo ve la petición B (otro usuario). Estas reglas
evitan esa fuga. Servidor: `OCTANE_SERVER=frankenphp` (`.env.example`).

## 1. Sin propiedades estáticas mutables

PHP no admite `static readonly`: cualquier propiedad `static` es mutable. En `App\` **no se
declaran**; usa constantes, `config()`, atributos de la petición o un binding del contenedor.

`tests/Arch/ArchTest.php` → «no hay propiedades estáticas mutables en App» recorre `app/` con
una expresión regular y falla si encuentra `static $x`. Excepciones justificadas y listadas en
el propio test:

- `app/Models/Tenant.php` (`$currentTenant`): se rehace en la fase de multiempresa.
- `app/Filament/`: metadatos estáticos que exige Filament (`$model`, `$navigationIcon`,
  `$slug`…), fijados en la declaración y nunca mutados.

Ejemplo real de cómo evitarlo: `LogService::setTraceId()` guarda el trace id en
`request()->attributes`, no en una propiedad estática. `Helpers\Feature::enabled()` no cachea
nada por la misma razón: `config()` ya está en memoria.

## 2. `bind`, `scoped` o `singleton`

Los bindings viven en `app/Providers/ContractServiceProvider.php` (ahí está la explicación).

| Binding | Cuándo | Ejemplo en el repo |
|---|---|---|
| `bind` (por defecto) | Servicios de aplicación: instancia nueva por resolución, nunca arrastran estado | los 22 servicios del provider |
| `scoped` | Necesita estado **por petición** pero compartido dentro de ella | no hay ninguno hoy |
| `singleton` | Colaborador **sin estado** y costoso de crear | `HostResolverInterface` → `DnsHostResolver` |

Regla: ante la duda, `bind`. Nada con estado de petición va en `singleton`.

## 3. No guardar petición ni usuario en servicios

Un servicio (o cualquier objeto que viva más que la petición) no guarda `Request`, `User` ni
`auth()->user()` en una propiedad. Recíbelo como parámetro del método o léelo en el momento.

- `AccountsTransport` (`app/Mail/Transport/`) es el modelo a seguir: no guarda estado; la
  cuenta llega en una cabecera interna y el transport real se resuelve en cada envío.
- Servicios con propiedades de instancia (`CacheService::$tags`, `LlmGateway::$useCase`) son
  seguros solo porque **no** son singleton (`bind`, instancia nueva por resolución).

## 4. `auth('api')` explícito

`config/auth.php` define el guard por defecto con `AUTH_GUARD` (valor `web`); el middleware
`auth:api` lo cambia a `api` solo durante esa petición, y Octane no garantiza que otra
petición lo haya dejado igual. En código nuevo:

```php
$user = auth('api')->user();            // o Auth::guard('api')->user()
$request->user('api');
```

Ejemplos del repo: `AuthController` y `PasswordController` usan `Auth::guard('api')->user()`;
`AdaptiveRateLimitingMiddleware` usa `auth('api')->id()`. Las rutas con permisos usan siempre
`permission:x,api` (lo exige `PermissionCatalogTest`). El back-office fija `authGuard('web')`
explícitamente (`docs/backoffice.md`).

El guard JWT es un singleton que **cachea el último usuario**. Por eso existe
`OptionalAuthMiddleware` (`auth.optional`): resetea la propiedad `user` del guard antes de
resolver el token; sin eso, una petición anónima heredaría el usuario anterior. No lo
repliques: usa el middleware y lee `$request->attributes->get('auth_user')`.

## 5. Listeners de Octane activos (`config/octane.php`)

- `RequestTerminated` → `FlushUploadedFiles`: limpia los ficheros subidos de la petición.
- `OperationTerminated` → `FlushOnce` y `FlushTemporaryContainerInstances`.
- `OperationTerminated` → `DisconnectFromDatabases` **solo si** `OCTANE_DISCONNECT_DATABASES=true`
  (por defecto `false`: reutilizar la conexión es más rápido; actívalo si tras un reinicio de
  PgBouncer/BD ves conexiones muertas).
- `WorkerStarting` → `EnsureUploadedFilesAreValid` y `EnsureUploadedFilesCanBeMoved`.
- `CollectGarbage` está comentado (la recolección la gobierna `garbage` = 50).

Tras cambiar código o `.env` hay que recargar los workers (`make reload` / `make redeploy`).

## 6. Caché de permisos de Spatie

Spatie cachea roles y permisos 24 h en `PERMISSION_CACHE_STORE` (Redis por defecto) y se
invalida solo al modificar roles o permisos mediante modelos. Octane añade un listener de
reinicio por petición: `register_octane_reset_listener` (`PERMISSION_OCTANE_RESET`, `true`).
Déjalo activo.

Lo que **no** se invalida solo: los seeders y migraciones corren sin eventos de modelo.
Llama a mano a `app(PermissionRegistrar::class)->forgetCachedPermissions()` (lo hacen
`PermissionSeeder`, `RoleSeeder` y la migración `adapt_rbac_tables_for_spatie_permission`) o
ejecuta `permission:cache-reset`. Los tests también lo hacen cuando cambian permisos a mano
(`BackofficeAccessTest`).

## 7. Cómo probar fugas entre peticiones

Los tests normales arrancan una aplicación por test y **no** ven estas fugas. Qué hay y qué falta:

- `tests/Arch/ArchTest.php`: detecta estáticos mutables y controllers con servicios concretos.
- `tests/Concurrency/` (`make test-concurrency`): procesos PHP reales con `ConcurrentRunner`;
  sirve para carreras de BD, no para estado compartido en memoria.
- Para una fuga de estado en memoria, repite la petición **sobre la misma aplicación** dentro
  de un test, cambiando de actor entre medias, y comprueba que lo segundo no ve lo primero:

```php
// Esquema: `authHeaders()` es un helper de tu test que devuelve el Bearer del usuario.
test('una petición anónima no hereda el usuario de la anterior', function () {
    $this->withHeaders(authHeaders($usuario))->getJson('/algo-con-auth-optional');
    $this->getJson('/algo-con-auth-optional')->assertJsonPath('data.user', null);
});
```

Si añades un singleton o un guard nuevo, escribe un test así. Antes de dar por bueno un
servicio nuevo: ¿tiene propiedades de instancia que cambian? Entonces `bind`, nunca `singleton`.
