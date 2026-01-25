# Migración de Feature Flags a Base de Datos

## Estado Actual (Fase 8)

Los Feature Flags están implementados usando archivos de configuración (`config/features.php`) y variables de entorno (`.env`).

### Ventajas del sistema actual:
- ✅ Simple y rápido de implementar
- ✅ Cache automático de Laravel
- ✅ Fácil de versionar en Git
- ✅ No requiere migraciones de BD

### Limitaciones:
- ❌ Requiere deploy para cambiar flags
- ❌ No permite control por usuario
- ❌ No permite activación gradual automática
- ❌ Cambios afectan a todos los usuarios simultáneamente

---

## Migración Futura a Base de Datos

### Cuándo migrar:
- Cuando necesites cambiar flags sin hacer deploy
- Cuando necesites activar features para usuarios específicos
- Cuando necesites activación gradual automática (% de usuarios)
- Cuando tengas múltiples servidores y necesites sincronización

---

## Estructura de Base de Datos Propuesta

### Tabla: `features`

```sql
CREATE TABLE features (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    enabled BOOLEAN DEFAULT false NOT NULL,
    description TEXT,
    percentage INTEGER DEFAULT 0 CHECK (percentage >= 0 AND percentage <= 100),
    user_ids JSONB DEFAULT '[]'::jsonb,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_features_name ON features(name);
CREATE INDEX idx_features_enabled ON features(enabled);
CREATE INDEX idx_features_user_ids ON features USING GIN(user_ids);
```

### Campos explicados:
- `name`: Nombre único de la feature (ej: 'advanced_search')
- `enabled`: Si la feature está activada globalmente
- `description`: Descripción de la feature
- `percentage`: Porcentaje de usuarios que deben ver la feature (0-100)
- `user_ids`: Array JSON de IDs de usuarios específicos que tienen acceso
- `metadata`: Datos adicionales (ej: configuraciones específicas)

---

## Migración de Datos

### Script de migración:

```php
// database/migrations/XXXX_XX_XX_create_features_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('enabled')->default(false);
            $table->text('description')->nullable();
            $table->integer('percentage')->default(0);
            $table->jsonb('user_ids')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            
            $table->index('name');
            $table->index('enabled');
        });

        // Migrar features existentes desde config
        $features = config('features', []);
        
        foreach ($features as $name => $config) {
            DB::table('features')->insert([
                'name' => $name,
                'enabled' => $config['enabled'] ?? false,
                'description' => $config['description'] ?? null,
                'percentage' => 0,
                'user_ids' => json_encode([]),
                'metadata' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
```

---

## Modelo Feature

```php
// app/Infrastructure/Features/Models/Feature.php

namespace App\Infrastructure\Features\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $table = 'features';

    protected $fillable = [
        'name',
        'enabled',
        'description',
        'percentage',
        'user_ids',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'percentage' => 'integer',
        'user_ids' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Verifica si la feature está habilitada para un usuario específico
     */
    public function isEnabledForUser(?int $userId = null): bool
    {
        // Si está deshabilitada globalmente, retornar false
        if (!$this->enabled) {
            return false;
        }

        // Si hay usuarios específicos, verificar si el usuario está en la lista
        if (!empty($this->user_ids)) {
            return in_array($userId, $this->user_ids);
        }

        // Si hay porcentaje configurado, calcular si el usuario debe verlo
        if ($this->percentage > 0 && $userId !== null) {
            return ($userId % 100) < $this->percentage;
        }

        // Si está habilitada globalmente sin restricciones
        return true;
    }
}
```

---

## Actualización del Helper Feature

```php
// app/Helpers/Feature.php (versión con BD)

namespace App\Helpers;

use App\Infrastructure\Features\Models\Feature as FeatureModel;
use Illuminate\Support\Facades\Cache;

class Feature
{
    protected static array $cache = [];

    /**
     * Verifica si una feature está habilitada
     * 
     * @param string $feature Nombre de la feature
     * @param int|null $userId ID del usuario (opcional, para control por usuario)
     * @return bool
     */
    public static function enabled(string $feature, ?int $userId = null): bool
    {
        $cacheKey = "feature:{$feature}:{$userId ?? 'global'}";

        // Verificar cache en memoria primero
        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        // Verificar cache de Laravel (Redis/Memcached)
        $cached = Cache::remember($cacheKey, 300, function () use ($feature, $userId) {
            $featureModel = FeatureModel::where('name', $feature)->first();

            if (!$featureModel) {
                return false;
            }

            // Si hay userId, verificar acceso específico
            if ($userId !== null) {
                return $featureModel->isEnabledForUser($userId);
            }

            // Verificación global
            return $featureModel->enabled;
        });

        static::$cache[$cacheKey] = $cached;
        return $cached;
    }

    /**
     * Activa una feature
     */
    public static function enable(string $feature, ?int $percentage = null): bool
    {
        $featureModel = FeatureModel::where('name', $feature)->firstOrFail();
        
        $featureModel->update([
            'enabled' => true,
            'percentage' => $percentage ?? $featureModel->percentage,
        ]);

        static::clearCache($feature);
        
        return true;
    }

    /**
     * Desactiva una feature
     */
    public static function disable(string $feature): bool
    {
        $featureModel = FeatureModel::where('name', $feature)->firstOrFail();
        
        $featureModel->update(['enabled' => false]);
        
        static::clearCache($feature);
        
        return true;
    }

    /**
     * Limpia el cache de una feature específica o todas
     */
    public static function clearCache(?string $feature = null): void
    {
        if ($feature) {
            Cache::forget("feature:{$feature}:global");
            unset(static::$cache["feature:{$feature}:global"]);
        } else {
            static::$cache = [];
            Cache::flush(); // Cuidado: limpia todo el cache
        }
    }
}
```

---

## Ejemplos de Uso con BD

### Activación global:
```php
Feature::enable('advanced_search');
```

### Activación gradual (25% de usuarios):
```php
Feature::enable('advanced_search', 25);
```

### Activación para usuarios específicos:
```php
$feature = FeatureModel::where('name', 'beta_api')->first();
$feature->user_ids = [1, 5, 10, 15];
$feature->save();
Feature::clearCache('beta_api');
```

### Verificación por usuario:
```php
// En un controller
$userId = auth()->id();

if (Feature::enabled('advanced_search', $userId)) {
    // Usuario tiene acceso
}
```

---

## Plan de Migración

1. **Crear migración y modelo** (sin cambiar el helper actual)
2. **Migrar datos** desde `config/features.php` a BD
3. **Actualizar helper** para leer de BD (mantener compatibilidad con config)
4. **Testing exhaustivo** en staging
5. **Deploy gradual** con fallback a config si BD falla
6. **Monitoreo** de rendimiento y errores
7. **Eliminar código de config** una vez estable

---

## Notas Importantes

- ⚠️ **Cache**: Usar cache agresivo para evitar consultas constantes a BD
- ⚠️ **Fallback**: Mantener fallback a config durante la migración
- ⚠️ **Performance**: Considerar usar Redis para cache de features
- ⚠️ **Sincronización**: Si hay múltiples servidores, usar cache compartido (Redis)

---

## Referencias

- [Laravel Feature Flags Best Practices](https://laravel.com/docs/features)
- [Feature Toggles (Martin Fowler)](https://martinfowler.com/articles/feature-toggles.html)
