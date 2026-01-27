# Cache Warming

## Descripción

El sistema incluye cache warming automático para pre-cargar datos frecuentemente accedidos y mejorar el rendimiento después de deployments o reinicios del servidor.

## Comando Manual

```bash
# Ejecutar cache warming manualmente
php artisan cache:warm

# Forzar warming incluso si el cache ya existe
php artisan cache:warm --force
```

## Datos que se Pre-cargan

El comando `cache:warm` pre-carga los siguientes datos:

### 1. Roles y Permisos (RBAC)
- Todos los roles del sistema
- Todos los permisos del sistema
- Roles principales por nombre (admin, user, guest, etc.)
- Permisos agrupados por recurso

### 2. Configuraciones del Sistema
- Feature flags (`config('features')`)
- Configuración de API keys (`config('api-keys')`)
- Configuración de rate limiting (`config('rate-limiting')`)

### 3. Usuarios Recientes
- Los 50 usuarios más recientes
- Con sus relaciones (roles, permisos) pre-cargadas

### 4. Webhooks Activos
- Configuración de todos los webhooks activos
- Incluye URL, eventos suscritos y estado

### 5. Índices de Búsqueda
- Información de índices de Meilisearch
- Estado y última sincronización

## Ejecución Automática

### Programada (Scheduler)
El cache warming se ejecuta automáticamente:
- **Diariamente a las 01:00 AM** (configurado en `routes/console.php`)

### Post-Deployment
Después de cada deployment, ejecutar:

```bash
./scripts/post-deploy.sh
```

Este script ejecuta:
1. Limpieza de cache de configuración
2. Optimización para producción (si aplica)
3. Migraciones pendientes
4. **Cache warming**
5. Sincronización de índices de búsqueda

## Integración con CI/CD

### GitHub Actions / GitLab CI

```yaml
# Ejemplo para GitHub Actions
- name: Post-deployment tasks
  run: |
    php artisan migrate --force
    php artisan cache:warm
    php artisan search:sync-indexes
```

### Docker Compose

```yaml
# En docker-compose.yml, después de iniciar el servicio
command: >
  sh -c "
    php artisan migrate --force &&
    php artisan cache:warm &&
    php-fpm
  "
```

## Métricas

El comando registra métricas en los logs:
- Número de elementos cacheados
- Tiempo de ejecución en milisegundos
- Errores si ocurren

Ejemplo de log:
```
Cache warming completado exitosamente
items_warmed: 125
elapsed_time_ms: 234.56
```

## Troubleshooting

### Cache no se está calentando
1. Verificar que Redis esté disponible: `php artisan redis:test`
2. Verificar permisos de escritura en cache
3. Revisar logs: `storage/logs/laravel.log`

### Datos desactualizados
- El cache tiene TTL configurado (1-2 horas según tipo)
- Los datos se invalidan automáticamente cuando cambian
- Ejecutar `php artisan cache:warm --force` para forzar actualización

### Performance
- El comando está optimizado para no sobrecargar el sistema
- Limita usuarios a los 50 más recientes
- Usa servicios existentes que ya tienen lógica de cache
