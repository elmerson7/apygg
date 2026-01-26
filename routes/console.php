<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
        \Illuminate\Support\Facades\Log::error('Falló la limpieza de JWT blacklist');
    });

// Limpieza de tokens de recuperación de contraseña expirados: cada 24 horas
Schedule::command('auth:clean-reset-tokens')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló la limpieza de tokens de reset de contraseña');
    });

// Limpieza de logs antiguos: cada día a las 2 AM
Schedule::command('logs:clean')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló la limpieza de logs antiguos');
    });

// Generación de reportes: cada semana (lunes) a las 8 AM
Schedule::command('reports:generate')
    ->weeklyOn(1, '8:00') // Lunes a las 8 AM
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló la generación de reportes');
    });

// Backup de base de datos: cada día a las 3 AM
Schedule::command('backup:create --database')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló el backup de base de datos');
    });

// Limpieza de backups antiguos: cada día a las 4 AM (después de crear backup)
Schedule::command('backup:clean')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló la limpieza de backups antiguos');
    });

// Sincronización de índices de búsqueda: cada hora
Schedule::command('search:sync-indexes')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Falló la sincronización de índices de búsqueda');
    });

// Verificación de salud de servicios: cada 5 minutos
Schedule::command('health:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Health check falló');
    });

// Cache warming automático: después de migraciones y al inicio del día
// Se ejecuta automáticamente después de deployments mediante hooks
Schedule::command('cache:warm')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Cache warming falló');
    });
