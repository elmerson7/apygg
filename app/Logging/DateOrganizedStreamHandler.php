<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * DateOrganizedStreamHandler
 *
 * Handler personalizado que organiza logs en estructura año/mes/día
 * Ejemplo: storage/logs/2026/01/26/activity.log
 */
class DateOrganizedStreamHandler extends StreamHandler
{
    /**
     * Crear handler organizado por fecha
     * Método estático para que Laravel pueda instanciarlo correctamente
     *
     * @param  array  $config  Configuración con 'filename' y 'level'
     */
    public static function create(array $config): self
    {
        $filename = $config['filename'] ?? 'laravel.log';
        $level = $config['level'] ?? Logger::DEBUG;

        // Convertir nivel de string a constante de Monolog si es necesario
        if (is_string($level)) {
            $level = Logger::toMonologLevel($level);
        }

        // Crear path organizado por fecha: año/mes/día
        $datePath = now()->format('Y/m/d');
        $logPath = storage_path("logs/{$datePath}/{$filename}");

        // Crear directorio si no existe con permisos 775 (accesible desde WSL y contenedor)
        $directory = dirname($logPath);
        if (! is_dir($directory)) {
            // Crear directorio con permisos 775
            mkdir($directory, 0775, true);

            // Intentar cambiar propietario a appuser (UID 1000) para compatibilidad con WSL
            // Esto permite que los archivos sean editables desde el IDE en WSL
            if (function_exists('posix_geteuid')) {
                // Intentar cambiar a UID 1000 (appuser) si el proceso tiene permisos
                @chown($directory, 1000);
                @chgrp($directory, 1000);
            }

            // Asegurar permisos correctos en todos los directorios padre creados
            $parts = explode('/', str_replace(storage_path('logs/'), '', $directory));
            $currentPath = storage_path('logs');
            foreach ($parts as $part) {
                if ($part) {
                    $currentPath .= '/'.$part;
                    if (is_dir($currentPath)) {
                        @chmod($currentPath, 0775);
                        if (function_exists('posix_geteuid')) {
                            @chown($currentPath, 1000);
                            @chgrp($currentPath, 1000);
                        }
                    }
                }
            }
        }

        return new self($logPath, $level);
    }
}
