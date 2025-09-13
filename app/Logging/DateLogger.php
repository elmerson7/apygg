<?php

namespace App\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Illuminate\Support\Facades\File;

class DateLogger
{
    /**
     * Create a custom Monolog instance.
     */
    public function __invoke(array $config)
    {
        $logger = new Logger('daily-directory');
        
        // Generar la ruta del archivo de log con estructura yyyy/mm/dd.log
        $logPath = $this->generateLogPath();
        
        // Crear directorios si no existen
        $this->ensureDirectoryExists(dirname($logPath));
        
        // Asegurar que el archivo tenga permisos correctos para IDE
        $this->setIdeFriendlyPermissions($logPath, false);
        
        // Crear el handler con la ruta generada
        $handler = new StreamHandler($logPath, $config['level'] ?? Logger::DEBUG);
        
        // Configurar el formatter para mostrar stack traces completos
        $formatter = new LineFormatter(
            $config['format'] ?? "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            $config['date_format'] ?? 'Y-m-d H:i:s',
            true,  // allowInlineLineBreaks: permite saltos de línea en el mensaje
            true   // ignoreEmptyContextAndExtra: ignora contexto vacío
        );
        
        // Configurar para mostrar stack traces completos sin truncar
        $formatter->includeStacktraces(true);
        $formatter->setMaxNormalizeDepth(10);        // Profundidad máxima de normalización
        $formatter->setMaxNormalizeItemCount(10000); // Máximo número de items
        
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);
        
        // Agregar procesadores si están configurados
        if (isset($config['processors']) && is_array($config['processors'])) {
            foreach ($config['processors'] as $processor) {
                $logger->pushProcessor(new $processor);
            }
        }
        
        return $logger;
    }
    
    /**
     * Generar la ruta del archivo de log basada en la fecha actual
     */
    private function generateLogPath(): string
    {
        $date = now();
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('d');
        
        return storage_path("logs/{$year}/{$month}/{$day}.log");
    }
    
    /**
     * Asegurar que el directorio y archivo existen con permisos correctos para IDE
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!File::exists($directory)) {
            // Crear directorio con permisos que permitan edición desde IDE
            File::makeDirectory($directory, 0777, true);
            
            // Establecer permisos específicos después de crear
            $this->setIdeFriendlyPermissions($directory, true);
        }
    }
    
    /**
     * Establecer permisos automáticamente SOLO para carpetas de fechas y archivos .log
     */
    private function setIdeFriendlyPermissions(string $path, bool $isDirectory = false): void
    {
        try {
            if ($isDirectory) {
                // Solo modificar permisos si es una carpeta de fecha (año/mes)
                if ($this->isDateDirectory($path)) {
                    chmod($path, 0777);
                }
            } else {
                // Solo modificar archivos .log que creamos nosotros
                if (pathinfo($path, PATHINFO_EXTENSION) === 'log') {
                    // Crear archivo si no existe
                    if (!file_exists($path)) {
                        touch($path);
                    }
                    
                    // Permisos para archivos de log: lectura y escritura para todos
                    chmod($path, 0666);
                }
            }
            
            // Intentar establecer grupo www-data solo para nuestros archivos
            if (pathinfo($path, PATHINFO_EXTENSION) === 'log' || $this->isDateDirectory($path)) {
                $currentUser = get_current_user();
                if ($currentUser === 'www-data' || function_exists('posix_getpwuid')) {
                    @chgrp($path, 'www-data');
                }
            }
            
        } catch (Exception $e) {
            // Si no se pueden cambiar permisos, usar umask más permisivo solo para nuestros archivos
            if (pathinfo($path, PATHINFO_EXTENSION) === 'log' || $this->isDateDirectory($path)) {
                $oldUmask = umask(0);
                
                if ($isDirectory && !File::exists($path)) {
                    File::makeDirectory($path, 0777, true);
                } elseif (!$isDirectory && !file_exists($path)) {
                    touch($path);
                    chmod($path, 0666);
                }
                
                umask($oldUmask);
            }
        }
    }
    
    /**
     * Verificar si un directorio es una carpeta de fecha que nosotros creamos
     */
    private function isDateDirectory(string $path): bool
    {
        $relativePath = str_replace(storage_path('logs'), '', $path);
        $relativePath = trim($relativePath, '/');
        
        // Verificar patrones: "2025", "2025/09", etc.
        // Solo carpetas de año (4 dígitos) o año/mes (4 dígitos/2 dígitos)
        return preg_match('/^\d{4}$/', $relativePath) || 
               preg_match('/^\d{4}\/\d{2}$/', $relativePath);
    }
}
