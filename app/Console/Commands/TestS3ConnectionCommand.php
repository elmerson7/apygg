<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestS3ConnectionCommand extends Command
{
    protected $signature = 'test:s3';

    protected $description = 'Probar conexión a S3/MinIO';

    public function handle(): int
    {
        $this->info('Probando conexión a S3/MinIO...');

        try {
            $disk = Storage::disk('s3');
            $config = config('filesystems.disks.s3');

            $this->line('Configuración S3:');
            $this->table(
                ['Parámetro', 'Valor'],
                [
                    ['Driver', $config['driver'] ?? 'N/A'],
                    ['Bucket', $config['bucket'] ?? 'N/A'],
                    ['Region', $config['region'] ?? 'N/A'],
                    ['Endpoint', $config['endpoint'] ?? 'N/A'],
                    ['Key', $config['key'] ? '***' : 'N/A'],
                    ['Secret', $config['secret'] ? '***' : 'N/A'],
                ]
            );

            // Probar escribir
            $this->info('Probando escritura...');
            $testPath = 'backups/test-'.time().'.txt';

            try {
                // Verificar primero si podemos listar (para verificar acceso al bucket)
                $this->line('Verificando acceso al bucket...');

                try {
                    $disk->files('backups');
                    $this->info('✓ Acceso al bucket verificado');
                } catch (\Exception $e) {
                    $this->warn('⚠ No se pudo listar archivos del bucket (puede ser normal si está vacío): '.$e->getMessage());
                }

                $this->line('Intentando subir archivo...');
                $uploaded = $disk->put($testPath, 'test content');
            } catch (\Aws\S3\Exception\S3Exception $e) {
                $this->error('Excepción AWS S3:');
                $this->line('Mensaje: '.$e->getMessage());
                $this->line('Código: '.$e->getAwsErrorCode());
                $this->line('Mensaje AWS: '.($e->getAwsErrorMessage() ?? 'N/A'));

                throw $e;
            } catch (\Exception $e) {
                $this->error('Excepción al subir: '.$e->getMessage());
                $this->line('Tipo: '.get_class($e));
                $this->line('Trace: '.$e->getTraceAsString());

                throw $e;
            }

            if ($uploaded) {
                $this->info("✓ Archivo subido exitosamente: {$testPath}");

                // Probar lectura
                $this->info('Probando lectura...');
                $content = $disk->get($testPath);
                if ($content === 'test content') {
                    $this->info('✓ Archivo leído exitosamente');

                    // Limpiar
                    $disk->delete($testPath);
                    $this->info('✓ Archivo de prueba eliminado');

                    $this->info('');
                    $this->info('✓ Conexión a S3/MinIO funcionando correctamente');

                    return Command::SUCCESS;
                } else {
                    $this->error('✗ Error: Contenido leído no coincide');
                }
            } else {
                $this->error('✗ Error: No se pudo subir el archivo');
            }
        } catch (\Exception $e) {
            $this->error('✗ Error: '.$e->getMessage());
            $this->line('');
            $this->line('Detalles:');
            $this->line($e->getTraceAsString());
        }

        return Command::FAILURE;
    }
}
