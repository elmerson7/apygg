# Migraciones

PostgreSQL 18 exclusivamente. Plantilla y reglas básicas: `docs/CODING_STANDARDS.md` →
«Migración». Aquí, las normas de fondo.

> **Consolidación previa a v1.0.0.** Mientras ningún proyecto derivado existía, las 73 migraciones
> históricas del boilerplate se reescribieron en 54 migraciones de creación (`create_<tabla>_table`,
> prefijo `2026_01_01_*`, más `0001_01_01_*` de Laravel): cada una crea su tabla completa y en su forma
> final, sin `add_*`/`alter`/`drop_*` ni tablas creadas para borrarse (el esquema resultante es idéntico).
> **A partir de ahora la regla de inmutabilidad de este documento (no reescribir, expandir/contraer)
> aplica a los proyectos derivados y al boilerplate tras la v1.0.0**, porque los derivados reciben las
> migraciones por merge (`docs/upgrading-from-boilerplate.md`). La consolidación **no es un ejemplo a
> imitar** en un proyecto derivado. Los nombres históricos que aparecen más abajo (p. ej.
> `convert_json_columns_to_jsonb`) ya no existen como archivo: ilustran el patrón correcto.

## 1. Nunca edites una migración ya publicada

Una migración que ya corrió en algún entorno (producción, el entorno de otro compañero, un
proyecto derivado) **no se toca**. Laravel no la vuelve a ejecutar: tu cambio solo llega a las
bases nuevas, y cada entorno queda con un esquema distinto. Crea una migración nueva.

**El caso real (commit `a13b86f`).** Tras la versión 1.0.0, ese commit cambió la clave primaria
de `uuid` a autoincremental **editando en sitio** las migraciones `2024_01_01_000001…000015`
(y las de `files`, `webhook_deliveries` y `tenants` en el mismo cambio de IDs); commits posteriores (`136ea00`, `7b1b252`, `6bdc0d6`)
añadieron `username`, `identity_document` y `preferences` a `create_users_table` del mismo modo.
Resultado: no hay migración de conversión; una BD creada con 1.0.0 (IDs uuid) no se puede
actualizar con `migrate`, y los proyectos derivados tienen que rehacer la base. Está anotado
en `docs/upgrading.md` → «0. Antes de merge». Es un coste que no se debe repetir.

**Cómo se hace bien** (patrón; esas migraciones se consolidaron antes de v1.0.0): `convert_json_columns_to_jsonb` convertía las columnas
`json` antiguas **sin reescribir** las migraciones que las crearon; `add_token_version_to_users_table`
añade una columna a `users` en una migración propia.

Excepción: una migración que **aún no se ha mergeado** a `main` ni desplegado en ningún sitio
puede editarse libremente en su rama. Una vez en `main`, es inmutable.

Todo cambio que rompa proyectos derivados se anota en `docs/upgrading.md`.

## 2. Desplegar sin parada: expandir y contraer

Durante un despliegue conviven código viejo y nuevo con la misma BD (workers de Octane y de
Horizon se reinician de uno en uno). Cada migración debe ser compatible con el código anterior.

| Cambio | Cómo, en varias entregas |
|---|---|
| Añadir columna | 1) migración que añade la columna **nullable** o con `default`; 2) código que la escribe y la lee |
| Hacerla obligatoria | 3) rellenar los datos antiguos; 4) migración posterior con `NOT NULL` |
| Renombrar columna | Añadir la nueva → escribir en ambas → copiar datos → leer de la nueva → quitar la vieja en una entrega posterior |
| Eliminar columna/tabla | 1) el código deja de usarla y se despliega; 2) **después** la migración que la borra |
| Cambiar tipo | Columna nueva + copia + cambio de lectura + borrado (ver punto 6 si reescribe la tabla) |

Un `NOT NULL` sin `default` sobre una tabla con filas, o un `dropColumn` en la misma entrega que
el código que deja de usarla, rompe a los workers que aún corren el código viejo.

Índices en tablas grandes: `CREATE INDEX CONCURRENTLY` (fuera de transacción:
`public $withinTransaction = false;` en la migración) para no bloquear escrituras. Una migración
que **reescribe** una tabla grande (`ALTER COLUMN ... TYPE`, como la de `jsonb`) va en ventana
de mantenimiento y se avisa en su docblock, como lo hacía la antigua `convert_json_columns_to_jsonb`.

## 3. `jsonb`, siempre

`$table->jsonb('metadata')`, nunca `json()`. `tests/Feature/JsonbColumnsTest.php` falla si
queda una columna `json` en la BD. Las migraciones de creación ya declaran `jsonb()` directamente.
Para búsquedas dentro del JSON usa operadores de jsonb y, si se filtra, un índice GIN.

## 4. Estados: `string` (y enum PHP), no `enum()` de la BD

Lo que hace el repo hoy es mixto:

- Las migraciones antiguas usan `$table->enum('status', [...])` (`webhooks`,
  `webhook_deliveries`): añadir un valor exige una migración que altere la restricción.
- Las nuevas usan `$table->string('status')->default('pending')` (`mail_logs`, `mail_events`)
  y la lista de valores vive en el código: constantes del modelo (`MailLog::STATUS_FAILED`,
  `WebhookDelivery::STATUS_PENDING`) o un `enum` PHP de `App\Enums` (`WebhookStatusEnum`,
  validado con `Rule::in([...->value])` en los Form Requests).

**Norma para lo nuevo:** columna `string`, valores definidos en un `enum` PHP de `app/Enums/`
(`enum EstadoX: string`), cast del modelo a ese enum y `Rule::enum()` en el Form Request. Añadir
un estado = tocar el enum, sin migración. Además se **recomienda** una restricción `CHECK` en la
BD para que ningún script salte la validación:

```php
DB::statement("ALTER TABLE pedidos ADD CONSTRAINT pedidos_status_check CHECK (status IN ('pending','paid','cancelled'))");
```

(Hoy ninguna migración del repo usa `CHECK`; es una norma nueva, no una práctica existente.
Cuando se añada un estado, la migración amplía el `CHECK`.) Nunca guardes el estado como
entero «mágico» ni como booleanos múltiples (`is_paid`, `is_sent`…).

## 5. Índices y claves

- Índice en toda columna por la que se filtra u ordena, y compuesto en el orden de la consulta:
  `index(['mail_account_id', 'status'])`, `index(['webhook_id', 'status'])`.
- Unicidad en la BD, no solo en la validación: `unique()` (ej. `mail_events.provider_event_id`,
  base de la idempotencia). Es lo único que resiste a las carreras.
- `foreignId()->constrained()` con `cascadeOnDelete()`, `restrictOnDelete()` o `nullOnDelete()`
  **explícito** (ver `security_scans`, `user_profiles`).
- `softDeletes()` si el modelo hereda de `App\Models\Model`; un único índice `unique` con
  soft delete necesita pensar en filas borradas (índice parcial `WHERE deleted_at IS NULL`).

## 6. Reversibles: `down()` siempre

Las 35 migraciones del repo tienen `down()`. Debe deshacer exactamente lo que hizo `up()`
(`dropColumn`, `dropIfExists`, o la conversión inversa, como hacía la antigua `convert_json_columns_to_jsonb`).
Si una migración **no se puede** revertir sin perder datos (borrado de columna), dilo en el
docblock y haz que `down()` recree la estructura aunque no pueda recuperar los datos. Hazla
idempotente cuando sea barato (`Schema::hasColumn` antes de tocar).

## 7. Datos frente a esquema

- Una migración de **esquema** no toca datos de negocio; una de **datos** (rellenar, convertir)
  va en **su propia migración**, separada, para poder reintentarla y revertirla.
- Los **datos de referencia** (roles, permisos, plantillas) van en seeders idempotentes
  (`PermissionSeeder` usa `firstOrCreate`; `PermissionCatalogTest` comprueba que correr los
  seeders dos veces no duplica), no en migraciones.
- En una migración, usa `DB::table()` o SQL, **no modelos Eloquent**: el modelo de mañana puede
  cambiar o no existir y la migración vieja dejaría de correr (y los eventos/observers del
  modelo dispararían efectos secundarios).
- Tablas grandes: procesa por lotes (`chunkById`) y no en una sola sentencia.
- Si cambias roles o permisos, vacía la caché de Spatie
  (`app(PermissionRegistrar::class)->forgetCachedPermissions()`, como hace la migración
  `create_permission_pivot_tables`).

## 8. Cómo probar una migración

- Entorno de pruebas: BD aislada `<DB>_test`; tras añadir migraciones corre
  `make testdb-migrate` (`docs/testing-database.md`). Si quedan desfasadas, los tests fallan con
  columnas inexistentes.
- **Ida y vuelta** en local: `make art cmd="migrate"`, `make art cmd="migrate:rollback --step=1"`,
  `make art cmd="migrate"`. Debe funcionar sin errores y dejar el mismo esquema
  (`make schema-dump` y compara `database/schema.sql`).
- **Migración de datos:** prepara el estado «antes» con filas representativas (incluye nulos,
  vacíos y valores límite), ejecuta la migración y comprueba el «después». Hazlo en un test que
  inserte con `DB::table()` el estado antiguo, ejecute la migración y consulte:

  ```php
  $migration = require database_path('migrations/2026_xx_xx_000001_convertir_estados.php');
  DB::table('pedidos')->insert(['status' => 'old_value', /* ... */]);
  $migration->up();
  expect(DB::table('pedidos')->value('status'))->toBe('new_value');
  $migration->down();   // y comprueba la vuelta
  ```

  (Esquema orientativo; `JsonbColumnsTest` es el ejemplo de test de esquema del repo.) En una
  copia de datos reales (`make restore-backup` en local), mide el tiempo antes de producción.
- Prueba también que es **idempotente** si la escribiste así (la antigua `convert_json_columns_to_jsonb`
  se saltaba lo que ya estaba convertido).

## 9. Cambios sin parada en PostgreSQL 18 y linter

`php artisan migrations:lint` (o `make migrations-lint`) revisa el `up()` de las migraciones
**posteriores** a la base permitida (`config/migration-lint.php` → `baseline`: las ya aplicadas en
`main` no se vuelven a juzgar; `--all` las revisa todas). Corre en CI (job `migrations`) y falla con
`archivo:línea [regla] motivo`. Reglas:

| Regla | Qué detecta | Alternativa segura |
|---|---|---|
| `change-type` | `->change()` | columna nueva + copia + cambio de lectura + borrado |
| `rename-column` | `renameColumn`, `RENAME COLUMN` | expandir/contraer (§2) |
| `drop-column` | `dropColumn`, `dropIfExists`, `DROP COLUMN/TABLE` en `up()` | borrar en una entrega posterior a la que dejó de usarla (con marca de excepción) |
| `index-not-concurrent` | `index/unique/fullText` en `Schema::table` o `CREATE INDEX` de una tabla de `large_tables` | `CREATE INDEX CONCURRENTLY` con `$withinTransaction = false` |
| `not-null-no-default` | columna sin `nullable()`/`default()` añadida a tabla existente; `SET NOT NULL`; `ADD COLUMN ... NOT NULL` sin `DEFAULT` | nullable/default y `NOT NULL` en dos pasos |

Lo seguro pasa sin marcas: `Schema::create` (tablas nuevas, con sus índices), columnas
`nullable()` o con `default()`, índices en tablas que no son grandes.

**Excepción explícita** (di siempre el motivo en la revisión):

- Inline, en la línea de la sentencia o la anterior: `// migration-lint: ignore rename-column -- tabla vacía`.
- Para todo el archivo: la misma marca antes de `return new class`.
- Por lista: `config/migration-lint.php` → `exceptions` (`'2026_12_01_000010_x' => ['drop-column']`).
- `large_tables` se ajusta por proyecto (`MIGRATION_LINT_LARGE_TABLES=a,b,c`).

El linter es un análisis de texto: ayuda, no sustituye a la revisión (p. ej. no sabe si una tabla
tiene filas ni si un `dropColumn` tuvo su fase previa).

### Recetas

**Columna nueva.** `->nullable()` o `->default(x)`: en PostgreSQL 11+ un `default` constante no
reescribe la tabla. Hazla obligatoria después (receta de NOT NULL).

**Índice sin bloquear escrituras.**

```php
public $withinTransaction = false; // CONCURRENTLY no cabe en una transacción

public function up(): void
{
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS api_logs_user_id_idx ON api_logs (user_id)');
}

public function down(): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS api_logs_user_id_idx');
}
```

Si falla a medias deja un índice `INVALID`: el `IF NOT EXISTS` no lo arregla; bórralo y reintenta.

**NOT NULL en dos pasos** (evita el escaneo bajo bloqueo largo):

```php
// Entrega 1: restricción sin validar (no escanea) y backfill por lotes (receta siguiente)
DB::statement('ALTER TABLE pedidos ADD CONSTRAINT pedidos_total_nn CHECK (total IS NOT NULL) NOT VALID');
// Entrega 2: validar (bloqueo ligero, no frena escrituras)
DB::statement('ALTER TABLE pedidos VALIDATE CONSTRAINT pedidos_total_nn');
// Opcional: con el CHECK validado, SET NOT NULL en PG 12+ no reescanea; luego se puede quitar el CHECK.
DB::statement('ALTER TABLE pedidos ALTER COLUMN total SET NOT NULL'); // migration-lint: ignore not-null-no-default -- CHECK validado
```

**Claves foráneas sobre tablas con datos:** `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID`
y, en otra migración, `VALIDATE CONSTRAINT`.

**Backfill por lotes:** nunca un `UPDATE` de toda la tabla en la migración. Despacha un job
(`docs/queues-and-jobs.md`) que actualice por `chunkById` (con `DB::table`, no modelos) o hazlo en
una migración de datos aparte (§7). Es idempotente: `WHERE col IS NULL`.

**Renombrar:** añadir la nueva (nullable) → código escribe en ambas → backfill → código lee la
nueva → entrega posterior que borra la vieja (con marca `ignore drop-column`).

**Tiempos máximos.** Una migración que espera un bloqueo encola tras de sí a toda la aplicación.
La clase base opcional `App\Support\Database\SafeMigration` fija `lock_timeout` (5 s) y `statement_timeout`
(60 s) durante el bloque (`MIGRATION_LOCK_TIMEOUT`, `MIGRATION_STATEMENT_TIMEOUT`) y los restaura:

```php
return new class extends App\Support\Database\SafeMigration {
    public function up(): void
    {
        $this->withTimeouts(fn () => Schema::table('pedidos', fn (Blueprint $t) => $t->string('nota')->nullable()));
    }
};
```

Si falla por `lock_timeout`, simplemente se reintenta el despliegue en un momento de menos tráfico.

## 10. Prueba en CI: aplicar sobre la versión anterior y revertir

El job `migrations` de `.github/workflows/ci.yml` ejecuta `scripts/ci-migrations-check.sh <ref-base>`
(base del PR, o el commit previo en un push): migra el esquema del ref base (`migrate:fresh` con sus
migraciones) y siembra, aplica las migraciones de la rama, lanza un humo (`migrate:status`,
`db:table users`) y hace `migrate:rollback --step=<nuevas>` + `migrate` otra vez. Por eso cada
migración nueva necesita un `down()` real. El seeder usado es el de la rama: si depende de tablas
nuevas el paso avisa pero no bloquea. En local: `git stash` no hace falta, basta ejecutar el script
con una BD vacía y `DB_DATABASE` apuntando a ella.

## 11. Checklist de revisión de una migración

`up()` compatible con el código anterior · `down()` real · pasa `migrations:lint` o tiene excepción
justificada · índices de tablas grandes con `CONCURRENTLY` · backfill fuera de la migración de
esquema · probada ida y vuelta (§8).

