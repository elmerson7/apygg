<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================
// Scheduler de Tareas Programadas
// ============================================

// Limpieza de JWT blacklist expirados: cada hora
Schedule::command('jwt:clean-blacklist')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de JWT blacklist');
    });

// Limpieza de tokens de recuperación de contraseña expirados: cada 24 horas
Schedule::command('auth:clean-reset-tokens')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de tokens de reset de contraseña');
    });

// Limpieza de logs antiguos: cada día a las 2 AM
Schedule::command('logs:clean')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de logs antiguos');
    });

// Limpieza del historial de correo: cada día a las 2:30 AM
Schedule::command('mail:logs-clean')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza del historial de correo');
    });

// module:llm:start
// Limpieza de la auditoría de IA: cada día a las 2:45 AM
Schedule::command('llm:prune-logs')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de la auditoría de IA');
    });
// module:llm:end

// Generación de reportes: cada semana (lunes) a las 8 AM
Schedule::command('reports:generate')
    ->weeklyOn(1, '8:00') // Lunes a las 8 AM
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la generación de reportes');
    });

// Backup de base de datos: cada día a las 3 AM
Schedule::command('backup:create --database')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló el backup de base de datos');
    });

// Limpieza de backups antiguos: cada día a las 4 AM (después de crear backup)
Schedule::command('backup:clean')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de backups antiguos');
    });

// module:search:start
// Sincronización de índices de búsqueda: cada hora
Schedule::command('search:sync-indexes')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la sincronización de índices de búsqueda');
    });
// module:search:end

// Verificación de salud de servicios: cada 5 minutos
Schedule::command('health:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Health check falló');
    });

// Cache warming automático: después de migraciones y al inicio del día
// También se ejecuta automáticamente después de deployments mediante scripts/post-deploy.sh
Schedule::command('cache:warm')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Cache warming falló');
    });

// Monitoreo de métricas de caché: cada hora
Schedule::command('cache:metrics --alert-threshold=70')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Monitoreo de métricas de caché falló');
    });

// Cache warming después de migraciones (si se ejecutan migraciones automáticamente)
// Nota: Esto se puede activar en producción si las migraciones se ejecutan automáticamente
// Schedule::command('cache:warm')
//     ->after(function () {
//         // Se ejecutaría después de migraciones si se configura así
//     });

// Limpieza de claves de idempotencia caducadas: cada hora
Schedule::command('idempotency:clean')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de claves de idempotencia');
    });

// Webhooks entrantes: reencola recibos atascados (Redis caído al recibirlos) y borra los antiguos
// Intenciones de subida directa caducadas (objetos huérfanos y reservas de cuota): cada hora
Schedule::command('files:purge-intents')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de intenciones de subida directa');
    });

Schedule::command('webhooks:inbound-sweep')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Barrido de webhooks entrantes falló');
    });

// Notificaciones: entregas atascadas (cada 10 min) y retención (a diario)
Schedule::command('notifications:sweep')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Barrido de entregas de notificaciones falló');
    });

Schedule::command('notifications:purge')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Purga de notificaciones falló');
    });

// Retención de la auditoría de negocio (audit.retention_days): cada día a las 3:15 AM
Schedule::command('audit:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la retención de la auditoría');
    });

// module:export-import:start
// Exportar/importar: borra archivos caducados (docs/export-import.md)
Schedule::command('exports:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la limpieza de exportaciones e importaciones');
    });
// module:export-import:end

// module:privacy:start
// Privacidad: borrados de cuenta vencidos (cada hora) y caducidad de exportaciones (a diario 3:45)
Schedule::command('privacy:process-deletions')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló el procesado de borrados de cuenta');
    });
// module:privacy:end

// module:privacy:start
Schedule::command('privacy:purge')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Falló la purga de privacidad');
    });
// module:privacy:end

// module:billing:start
// Facturación (docs/billing.md): renovaciones vencidas, reintentos de cobro, altas sin pagar y cobros interrumpidos.
// Cada 15 minutos; seguro con varias instancias a la vez (bloqueo por suscripción + índices únicos).
Schedule::command('billing:renew')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('El ciclo de cobro (billing:renew) falló');
    });
// module:billing:end

// module:billing:start
// Avisos de facturación con antelación (docs/billing.md §Avisos): fin de prueba y renovación próxima. Idempotente.
Schedule::command('billing:notify')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Los avisos de facturación (billing:notify) fallaron');
    });
// module:billing:end

// module:billing:start
// Conciliación con la pasarela (docs/billing.md §Conciliación): cobros inciertos por clave/id, 3DS vencidos y cobros sin reflejo.
// Cada 10 minutos, antes de que `billing:renew` reenvíe nada (ambos son seguros en cualquier orden).
Schedule::command('payments:reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('La conciliación de pagos (payments:reconcile) falló');
    });
// module:billing:end
