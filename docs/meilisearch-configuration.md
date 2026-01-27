# Configuración de Meilisearch

## Descripción

Meilisearch es un motor de búsqueda full-text rápido y moderno. Laravel Scout proporciona una abstracción simple para integrar Meilisearch con Laravel.

## Instalación

Las dependencias ya están instaladas:
- `laravel/scout` ^10.17
- `meilisearch/meilisearch-php` ^1.15

## Configuración

### Variables de Entorno

```env
# Driver de búsqueda (meilisearch, algolia, database, null)
SCOUT_DRIVER=meilisearch

# Prefijo para índices (útil para multi-tenant)
SCOUT_PREFIX=

# Usar colas para sincronización (recomendado para producción)
SCOUT_QUEUE=true

# Host de Meilisearch
MEILISEARCH_HOST=http://meilisearch:7700

# Master Key de Meilisearch (valor por defecto: masterKey para desarrollo)
MEILISEARCH_KEY=masterKey

# Prefijo para configuración de índices
MEILISEARCH_INDEX_SETTINGS_PREFIX=
```

### Docker Compose

El servicio Meilisearch está configurado en `docker-compose.yml`:

```yaml
meilisearch:
  image: getmeili/meilisearch:v1.16
  container_name: apygg_meili
  profiles: ["dev", "staging", "prod"]
  environment:
    MEILI_MASTER_KEY: ${MEILISEARCH_KEY:-masterKey}
    MEILI_ENV: ${APP_ENV:-production}
    MEILI_NO_ANALYTICS: ${MEILISEARCH_NO_ANALYTICS:-true}
  ports:
    - "8013:7700"
  volumes:
    - meilidata:/meili_data
```

### Iniciar Servicio

```bash
# Desarrollo
docker compose --profile dev up -d meilisearch

# Producción
docker compose --profile prod up -d meilisearch
```

## Configuración de Scout

### Batch Size

El tamaño de batch está configurado en `config/scout.php`:

```php
'chunk' => [
    'searchable' => 500,    // Registros a indexar por batch
    'unsearchable' => 500,  // Registros a eliminar por batch
],
```

**Recomendaciones:**
- **Desarrollo**: 500 (por defecto)
- **Producción**: 500-1000 (ajustar según recursos del servidor)

### Sincronización

#### Automática

Scout sincroniza automáticamente cuando:
- Se crea un modelo (`created`)
- Se actualiza un modelo (`updated`)
- Se elimina un modelo (`deleted`)

#### Manual

```bash
# Sincronizar todos los modelos
php artisan scout:import "App\Models\User"

# Sincronizar modelo específico
php artisan scout:import "App\Models\User"

# Sincronizar con cola
php artisan scout:import "App\Models\User" --queue

# Flush (eliminar todos los índices)
php artisan scout:flush "App\Models\User"
```

### Colas

Para mejor rendimiento, activa la sincronización mediante colas:

```env
SCOUT_QUEUE=true
```

Esto enviará las operaciones de indexación a la cola de Redis.

## Uso Básico

### Hacer un Modelo Searchable

El modelo `User` ya está configurado como searchable. Ejemplo de implementación:

```php
use App\Traits\Searchable;

class User extends Model
{
    use Searchable;

    /**
     * Get the indexable data array for the model.
     * Sobrescribe el método del trait Searchable para personalizar los campos indexables.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->timestamp,
            'timezone' => $this->timezone,
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
            'is_admin' => $this->isAdmin(),
            'roles' => $this->roles->pluck('name')->toArray(),
            'role_ids' => $this->roles->pluck('id')->toArray(),
        ];
    }

    /**
     * Campos que deben ser filtrables en Meilisearch.
     */
    public function getFilterableAttributes(): array
    {
        return [
            'email_verified_at',
            'timezone',
            'is_admin',
            'roles',
            'role_ids',
            'created_at',
        ];
    }

    /**
     * Campos que deben ser ordenables en Meilisearch.
     */
    public function getSortableAttributes(): array
    {
        return [
            'name',
            'email',
            'created_at',
            'updated_at',
            'email_verified_at',
        ];
    }
}
```

### Búsqueda

```php
// Búsqueda simple
$users = User::search('john')->get();

// Búsqueda con paginación
$users = User::search('john')->paginate(20);

// Búsqueda con filtros (usando los atributos configurados)
$users = User::search('admin')
    ->where('is_admin', true)
    ->get();

// Búsqueda por roles
$users = User::search('john')
    ->where('roles', 'admin')
    ->get();

// Búsqueda con múltiples filtros
$users = User::search('test')
    ->where('is_admin', false)
    ->where('email_verified_at', '!=', null)
    ->get();

// Búsqueda con ordenamiento
$users = User::search('john')
    ->orderBy('created_at', 'desc')
    ->get();

// Búsqueda usando métodos helper del trait Searchable
$users = User::searchWithFilters('admin', ['is_admin' => true]);
$users = User::searchWithSort('john', 'created_at', 'desc');
$users = User::searchPaginated('test', 20, 1);
```

## Configuración Avanzada

### Configurar Índices

En `config/scout.php`:

```php
'meilisearch' => [
    'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
    'key' => env('MEILISEARCH_KEY') ?: 'masterKey', // Valor por defecto para desarrollo
    'index-settings' => [
        'users' => [
            'filterableAttributes' => [
                'email_verified_at',
                'timezone',
                'is_admin',
                'roles',
                'role_ids',
                'created_at',
            ],
            'sortableAttributes' => [
                'name',
                'email',
                'created_at',
                'updated_at',
                'email_verified_at',
            ],
            'searchableAttributes' => [
                'name',
                'email',
                'roles',
            ],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ],
        ],
    ],
],
```

### Filtros y Facetas

Los filtros disponibles para el modelo `User` están configurados en `config/scout.php` y en el método `getFilterableAttributes()` del modelo:

```php
// Búsqueda con filtros disponibles
$users = User::search('admin')
    ->where('is_admin', true)
    ->get();

// Filtrar por roles
$users = User::search('john')
    ->where('roles', 'admin')
    ->get();

// Filtrar por timezone
$users = User::search('test')
    ->where('timezone', 'America/Mexico_City')
    ->get();

// Filtrar por email verificado
$users = User::search('user')
    ->where('email_verified_at', '!=', null)
    ->get();

// Múltiples filtros combinados
$users = User::search('admin')
    ->where('is_admin', true)
    ->where('email_verified_at', '!=', null)
    ->orderBy('created_at', 'desc')
    ->paginate(20);
```

## Comandos Útiles

### Comandos Artisan

```bash
# Sincronizar configuración de índices (filtros, ordenamiento, etc.)
php artisan scout:sync-index-settings

# Importar modelo específico a Meilisearch
php artisan scout:import "App\Models\User"

# Importar con cola (recomendado para grandes volúmenes)
php artisan scout:import "App\Models\User" --queue

# Limpiar todos los registros de un modelo del índice
php artisan scout:flush "App\Models\User"

# Crear un índice manualmente
php artisan scout:index "users"

# Eliminar un índice
php artisan scout:delete-index "users"

# Eliminar todos los índices
php artisan scout:delete-all-indexes
```

### Comandos Makefile (Recomendados)

```bash
# Sincronizar configuración de índices
make scout

# Importar todos los modelos searchable
make scout-import

# Limpiar todos los índices
make scout-flush

# Resetear todos los índices
make scout-reset

# Sincronizar configuración (alternativa)
make scout-sync
```

### Verificación Directa con Meilisearch

```bash
# Verificar estado de Meilisearch
curl http://localhost:8013/health

# Ver índices (requiere autenticación)
curl -H "Authorization: Bearer masterKey" http://localhost:8013/indexes

# Ver configuración de un índice
curl -H "Authorization: Bearer masterKey" http://localhost:8013/indexes/users/settings
```

## Troubleshooting

### Meilisearch no responde

1. Verificar que el servicio esté corriendo:
   ```bash
   docker compose ps meilisearch
   ```

2. Verificar conectividad:
   ```bash
   curl http://localhost:8013/health
   ```

3. Verificar logs:
   ```bash
   docker compose logs meilisearch
   ```

### Índices no se sincronizan

1. Verificar configuración:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

2. Verificar que el modelo use el trait `Searchable`

3. Verificar que `toSearchableArray()` esté implementado

4. Sincronizar manualmente:
   ```bash
   # Sincronizar configuración primero
   make scout
   # O directamente:
   php artisan scout:sync-index-settings
   
   # Luego importar datos
   make scout-import
   # O directamente:
   php artisan scout:import "App\Models\User"
   ```

### Errores de autenticación

1. Verificar `MEILISEARCH_KEY` en `.env` o `env/dev.env`
   - Si está vacía, se usará el valor por defecto `masterKey` (configurado en `config/scout.php`)
   - Para desarrollo, `masterKey` es suficiente
   - Para producción, generar una key segura con: `make meilisearch-key`

2. Verificar que coincida con `MEILI_MASTER_KEY` en docker-compose.yml
   - El valor por defecto es `masterKey` si `MEILISEARCH_KEY` no está definida

3. Reiniciar servicios:
   ```bash
   docker compose restart meilisearch app
   # O con Makefile:
   make restart service=meilisearch
   make restart service=app
   ```

4. Limpiar caché de configuración:
   ```bash
   php artisan config:clear
   ```

## Mejores Prácticas

1. **Usar colas en producción**: `SCOUT_QUEUE=true` en `.env`
2. **Configurar batch size apropiado**: 500-1000 según recursos (ya configurado en `config/scout.php`)
3. **Indexar solo campos necesarios**: Optimizar `toSearchableArray()` (ya implementado en `User`)
4. **Usar filtros**: Mejorar rendimiento y relevancia (filtros configurados en `config/scout.php`)
5. **Sincronizar índices después de cambios**: `make scout` o `php artisan scout:sync-index-settings`
6. **Monitorear uso**: Revisar logs y métricas de Meilisearch
7. **Usar comandos del Makefile**: Más convenientes que los comandos artisan directos
8. **Generar key segura para producción**: `make meilisearch-key` y actualizar en `.env`

## Referencias

- [Laravel Scout Documentation](https://laravel.com/docs/scout)
- [Meilisearch Documentation](https://www.meilisearch.com/docs)
- [Meilisearch PHP Client](https://github.com/meilisearch/meilisearch-php)
