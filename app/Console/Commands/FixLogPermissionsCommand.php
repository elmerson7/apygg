<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * FixLogPermissionsCommand
 *
 * Comando para ajustar permisos de los directorios de logs organizados por fecha
 * Asegura que los logs sean accesibles desde WSL y el contenedor Docker
 */
class FixLogPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:fix-permissions {--recursive : Aplicar recursivamente a todos los subdirectorios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ajustar permisos de los directorios de logs organizados por fecha';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logsPath = storage_path('logs');
        $uid = 1000; // appuser UID (coincide con usuario WSL)
        $gid = 1000; // appuser GID

        if (! is_dir($logsPath)) {
            $this->error("El directorio de logs no existe: {$logsPath}");

            return self::FAILURE;
        }

        $this->info("Ajustando permisos de logs en: {$logsPath}");

        // Ajustar permisos del directorio base
        $this->setPermissions($logsPath, $uid, $gid);

        // Si --recursive, ajustar todos los subdirectorios
        if ($this->option('recursive')) {
            $directories = File::directories($logsPath);
            $count = 0;

            foreach ($directories as $directory) {
                $this->setPermissionsRecursive($directory, $uid, $gid);
                $count++;
            }

            $this->info("Permisos ajustados en {$count} directorios.");
        } else {
            $this->info('Permisos del directorio base ajustados.');
            $this->warn('Usa --recursive para ajustar todos los subdirectorios existentes.');
        }

        return self::SUCCESS;
    }

    /**
     * Establecer permisos en un directorio
     */
    protected function setPermissions(string $path, int $uid, int $gid): void
    {
        if (is_dir($path)) {
            @chmod($path, 0775);
            @chown($path, $uid);
            @chgrp($path, $gid);
            $this->line("  ✓ {$path}");
        }
    }

    /**
     * Establecer permisos recursivamente
     */
    protected function setPermissionsRecursive(string $path, int $uid, int $gid): void
    {
        if (! is_dir($path)) {
            return;
        }

        $this->setPermissions($path, $uid, $gid);

        // Ajustar archivos
        $files = File::files($path);
        foreach ($files as $file) {
            @chmod($file, 0664);
            @chown($file, $uid);
            @chgrp($file, $gid);
        }

        // Recursivo en subdirectorios
        $directories = File::directories($path);
        foreach ($directories as $directory) {
            $this->setPermissionsRecursive($directory, $uid, $gid);
        }
    }
}
