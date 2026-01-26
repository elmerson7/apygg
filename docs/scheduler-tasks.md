# Scheduler de Tareas Programadas

## Descripción

El scheduler de Laravel permite ejecutar tareas automáticas en horarios específicos. Todas las tareas están configuradas en `routes/console.php` y se ejecutan mediante `php artisan schedule:work` (desarrollo) o cron (producción).

## Configuración

### Archivo de Configuración

Las tareas se definen en `routes/console.php` usando `Schedule::command()`:

```php
Schedule::command('comando:nombre')
    ->frecuencia()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Error en tarea');
    });
```

### Ejecución del Scheduler

#### Desarrollo

```bash
# Ejecutar scheduler continuamente (recomendado para desarrollo)
php artisan schedule:work

# O ejecutar tareas pendientes una vez
php artisan schedule:run
```

#### Producción

**Opción 1: Cron (Recomendado)**

Agregar al crontab del servidor:

```bash
* * * * * cd /ruta/a/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

**Opción 2: Contenedor Docker**

Ejecutar contenedor separado con:

```bash
php artisan schedule:work
```

## Tareas Configuradas

### 1. Limpieza de JWT Blacklist

**Comando:** `jwt:clean-blacklist`  
**Frecuencia:** Cada hora  
**Hora:** :00 de cada hora  
**Descripción:** Elimina tokens JWT expirados de la blacklist

**Uso manual:**
```bash
php artisan jwt:clean-blacklist
```

**Qué hace:**
- Busca tokens en `jwt_blacklist` con `expires_at < now()`
- Elimina tokens expirados
- Registra el número de tokens eliminados en logs

---

### 2. Limpieza de Tokens de Reset de Contraseña

**Comando:** `auth:clean-reset-tokens`  
**Frecuencia:** Diariamente  
**Hora:** 00:00 (medianoche)  
**Descripción:** Elimina tokens de recuperación de contraseña expirados (>1 hora)

**Uso manual:**
```bash
php artisan auth:clean-reset-tokens
```

**Qué hace:**
- Busca tokens en `password_reset_tokens` creados hace más de 1 hora
- Elimina tokens expirados
- Registra el número de tokens eliminados en logs

---

### 3. Limpieza de Logs Antiguos

**Comando:** `logs:clean`  
**Frecuencia:** Diariamente  
**Hora:** 02:00 AM  
**Descripción:** Elimina logs antiguos según TTL configurado

**Uso manual:**
```bash
# Usar TTL por defecto (90 días)
php artisan logs:clean

# Personalizar días
php artisan logs:clean --days=30
```

**TTL por Tipo de Log:**
- **API Logs**: 90 días (por defecto)
- **Error Logs**: 180 días (mínimo)
- **Security Logs**: 365 días (mínimo)
- **Activity Logs**: 730 días (mínimo)

**Qué hace:**
- Elimina logs de API anteriores a la fecha de corte
- Elimina logs de errores anteriores a 180 días (o el parámetro si es mayor)
- Elimina logs de seguridad anteriores a 365 días (o el parámetro si es mayor)
- Elimina logs de actividad anteriores a 730 días (o el parámetro si es mayor)
- Muestra estadísticas de eliminación por tipo

---

### 4. Generación de Reportes Semanales

**Comando:** `reports:generate`  
**Frecuencia:** Semanalmente  
**Día:** Lunes  
**Hora:** 08:00 AM  
**Descripción:** Genera reportes semanales del sistema

**Uso manual:**
```bash
php artisan reports:generate
```

**Qué incluye el reporte:**
- **Usuarios**: Total, nuevos, activos
- **API Logs**: Total, errores (status >= 400)
- **Error Logs**: Total, críticos
- **Security Logs**: Total, actividad sospechosa
- **Activity Logs**: Total

**Salida:**
- JSON con todas las métricas
- Registrado en logs
- Puede extenderse para guardar en BD o enviar por email

---

### 5. Backup de Base de Datos

**Comando:** `db:backup`  
**Frecuencia:** Diariamente  
**Hora:** 03:00 AM  
**Descripción:** Crea backup comprimido de PostgreSQL

**Uso manual:**
```bash
# Backup sin comprimir
php artisan db:backup

# Backup comprimido (recomendado)
php artisan db:backup --compress
```

**Qué hace:**
- Ejecuta `pg_dump` para crear backup
- Guarda en `storage/app/backups/backup_{database}_{timestamp}.sql`
- Opcionalmente comprime con gzip
- Registra tamaño y ubicación del backup

**Ubicación de backups:**
```
storage/app/backups/
├── backup_apygg_2026-01-26_03-00-00.sql.gz
├── backup_apygg_2026-01-27_03-00-00.sql.gz
└── ...
```

**Nota:** Considera agregar lógica para:
- Subir backups a S3 u otro almacenamiento
- Eliminar backups antiguos (mantener solo últimos N)
- Enviar notificación de éxito/fallo

---

### 6. Sincronización de Índices de Búsqueda

**Comando:** `search:sync-indexes`  
**Frecuencia:** Cada hora  
**Hora:** :00 de cada hora  
**Descripción:** Sincroniza índices de Meilisearch con modelos

**Uso manual:**
```bash
php artisan search:sync-indexes
```

**Qué hace:**
- Verifica si Scout está configurado
- Sincroniza modelos con trait `Searchable`
- Actualiza índices de búsqueda en Meilisearch

**Modelos sincronizados:**
- `User` (si tiene trait `Searchable`)

**Nota:** Agregar más modelos según necesidad del proyecto.

---

### 7. Verificación de Salud de Servicios

**Comando:** `health:check`  
**Frecuencia:** Cada 5 minutos  
**Hora:** */5 (cada 5 minutos)  
**Descripción:** Verifica salud de servicios críticos

**Uso manual:**
```bash
php artisan health:check
```

**Qué verifica:**
- **Base de datos**: Conexión a PostgreSQL
- **Redis**: Conexión y ping
- **Meilisearch**: Health endpoint (si está configurado)

**Salida:**
- ✓ Servicio OK
- ✗ Servicio con ERROR

**Códigos de retorno:**
- `0` (SUCCESS): Todos los servicios OK
- `1` (FAILURE): Algún servicio con problemas

---

## Ver Tareas Programadas

### Listar todas las tareas

```bash
php artisan schedule:list
```

**Salida ejemplo:**
```
  0   * * * *  php artisan jwt:clean-blacklist . Next Due: 16 minutes from now
  0   0 * * *  php artisan auth:clean-reset-tokens  Next Due: 22 hours from now
  0   2 * * *  php artisan logs:clean .......... Next Due: 16 minutes from now
  0   8 * * 1  php artisan reports:generate ....... Next Due: 6 hours from now
  0   3 * * *  php artisan db:backup --compress .... Next Due: 1 hour from now
  0   * * * *  php artisan search:sync-indexes . Next Due: 16 minutes from now
  */5 * * * *  php artisan health:check .......... Next Due: 1 minute from now
```

### Verificar próxima ejecución

```bash
php artisan schedule:test
```

---

## Características de las Tareas

### Sin Solapamiento (`withoutOverlapping()`)

Todas las tareas tienen `withoutOverlapping()` para evitar que se ejecuten simultáneamente si la ejecución anterior aún no ha terminado.

### Manejo de Errores (`onFailure()`)

Cada tarea tiene un callback `onFailure()` que registra errores en logs cuando falla.

### Logging

Todas las tareas registran:
- Inicio de ejecución
- Resultados (éxito/fallo)
- Estadísticas (registros eliminados, backups creados, etc.)
- Errores con contexto completo

---

## Agregar Nueva Tarea

### 1. Crear Comando

```bash
php artisan make:command MiNuevoComando
```

### 2. Implementar Lógica

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MiNuevoComando extends Command
{
    protected $signature = 'mi:comando';
    protected $description = 'Descripción del comando';

    public function handle(): int
    {
        // Tu lógica aquí
        return Command::SUCCESS;
    }
}
```

### 3. Agregar al Scheduler

En `routes/console.php`:

```php
Schedule::command('mi:comando')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló mi comando');
    });
```

---

## Frecuencias Disponibles

```php
// Cada minuto
->everyMinute()

// Cada 5 minutos
->everyFiveMinutes()

// Cada hora
->hourly()

// Diariamente a medianoche
->daily()

// Diariamente a hora específica
->dailyAt('14:00')

// Semanalmente (lunes a las 8 AM)
->weeklyOn(1, '8:00')

// Mensualmente
->monthly()

// Personalizado (cron)
->cron('0 0 * * *') // Diario a medianoche
```

---

## Monitoreo y Debugging

### Ver logs de ejecución

```bash
# Logs de Laravel
tail -f storage/logs/laravel.log

# Filtrar por scheduler
grep "schedule\|Scheduler" storage/logs/laravel.log
```

### Ejecutar tarea manualmente para probar

```bash
# Ejecutar comando directamente
php artisan jwt:clean-blacklist

# Verificar que funciona
php artisan schedule:test
```

### Verificar que el scheduler está corriendo

```bash
# Ver procesos
ps aux | grep "schedule:work"

# Ver logs del scheduler
docker compose logs scheduler
```

---

## Producción

### Configuración Recomendada

1. **Cron Job** (Recomendado):
   ```bash
   * * * * * cd /ruta/a/proyecto && php artisan schedule:run >> /dev/null 2>&1
   ```

2. **Contenedor Docker Separado**:
   ```yaml
   scheduler:
     build: ./docker/app
     command: php artisan schedule:work
     volumes:
       - .:/app
     depends_on:
       - postgres
       - redis
   ```

### Consideraciones

- **Overlap Prevention**: Ya configurado con `withoutOverlapping()`
- **Logging**: Todas las tareas registran en logs
- **Notificaciones**: Puedes agregar notificaciones en `onFailure()`
- **Retención de Backups**: Considera agregar lógica para eliminar backups antiguos
- **Monitoreo**: Monitorea logs y métricas de ejecución

---

## Troubleshooting

### El scheduler no ejecuta tareas

1. Verificar que cron está corriendo:
   ```bash
   crontab -l
   ```

2. Verificar permisos:
   ```bash
   chmod +x artisan
   ```

3. Verificar logs:
   ```bash
   tail -f storage/logs/laravel.log
   ```

### Tarea falla repetidamente

1. Ejecutar manualmente para ver error:
   ```bash
   php artisan nombre:comando
   ```

2. Verificar dependencias (DB, Redis, etc.)

3. Revisar logs de la tarea específica

### Tarea tarda mucho

1. Verificar si hay overlap (no debería con `withoutOverlapping()`)

2. Optimizar consultas en el comando

3. Considerar ejecutar en cola de baja prioridad

---

## Resumen de Tareas

| Tarea | Comando | Frecuencia | Hora |
|-------|---------|------------|------|
| Limpieza JWT Blacklist | `jwt:clean-blacklist` | Cada hora | :00 |
| Limpieza Reset Tokens | `auth:clean-reset-tokens` | Diario | 00:00 |
| Limpieza Logs | `logs:clean` | Diario | 02:00 |
| Reportes Semanales | `reports:generate` | Semanal (Lunes) | 08:00 |
| Backup BD | `db:backup --compress` | Diario | 03:00 |
| Sincronización Búsqueda | `search:sync-indexes` | Cada hora | :00 |
| Health Check | `health:check` | Cada 5 min | */5 |

---

**Última actualización:** Enero 2026  
**Versión:** 1.0
