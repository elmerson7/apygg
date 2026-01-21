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

# Master Key de Meilisearch
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

```php
use Laravel\Scout\Searchable;

class User extends Model
{
    use Searchable;

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at->timestamp,
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

// Búsqueda con filtros
$users = User::search('john')
    ->where('role', 'admin')
    ->get();

// Búsqueda con ordenamiento
$users = User::search('john')
    ->orderBy('created_at', 'desc')
    ->get();
```

## Configuración Avanzada

### Configurar Índices

En `config/scout.php`:

```php
'meilisearch' => [
    'index-settings' => [
        'users' => [
            'filterableAttributes' => ['role', 'status', 'created_at'],
            'sortableAttributes' => ['created_at', 'updated_at', 'name'],
            'searchableAttributes' => ['name', 'email'],
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

```php
// En el modelo
public function toSearchableArray(): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'email' => $this->email,
        'role' => $this->role,        // Filtrable
        'status' => $this->status,    // Filtrable
        'created_at' => $this->created_at->timestamp,
    ];
}

// Búsqueda con filtros
$users = User::search('john')
    ->where('role', 'admin')
    ->where('status', 'active')
    ->get();
```

## Comandos Útiles

```bash
# Verificar estado de Meilisearch
curl http://localhost:8013/health

# Ver índices
curl http://localhost:8013/indexes

# Ver configuración de un índice
curl http://localhost:8013/indexes/users/settings

# Sincronizar índices
php artisan scout:sync-index-settings

# Importar todos los modelos
php artisan scout:import "App\Models\User"
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
   php artisan scout:import "App\Models\User"
   ```

### Errores de autenticación

1. Verificar `MEILISEARCH_KEY` en `.env`
2. Verificar que coincida con `MEILI_MASTER_KEY` en docker-compose.yml
3. Reiniciar servicios:
   ```bash
   docker compose restart meilisearch app
   ```

## Mejores Prácticas

1. **Usar colas en producción**: `SCOUT_QUEUE=true`
2. **Configurar batch size apropiado**: 500-1000 según recursos
3. **Indexar solo campos necesarios**: Optimizar `toSearchableArray()`
4. **Usar filtros**: Mejorar rendimiento y relevancia
5. **Sincronizar índices después de cambios**: `scout:sync-index-settings`
6. **Monitorear uso**: Revisar logs y métricas de Meilisearch

## Referencias

- [Laravel Scout Documentation](https://laravel.com/docs/scout)
- [Meilisearch Documentation](https://www.meilisearch.com/docs)
- [Meilisearch PHP Client](https://github.com/meilisearch/meilisearch-php)
