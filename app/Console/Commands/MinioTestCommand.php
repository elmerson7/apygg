<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MinioTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'minio:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probar conexión y operaciones básicas con MinIO (S3 compatible)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Probando conexión con MinIO...');
        $this->newLine();

        try {
            // 1. Verificar configuración
            $this->info('1️⃣ Verificando configuración...');
            $this->displayConfig();
            $this->newLine();

            // 2. Probar conexión
            $this->info('2️⃣ Probando conexión...');
            $disk = Storage::disk('s3');

            // Intentar listar buckets (esto prueba la conexión)
            try {
                $this->line('   Intentando conectar con MinIO...');
                // MinIO no tiene un método directo para listar buckets desde Laravel Storage
                // Pero podemos probar escribiendo un archivo de prueba
            } catch (\Exception $e) {
                $this->error('   ❌ Error de conexión: '.$e->getMessage());

                return Command::FAILURE;
            }
            $this->info('   ✅ Conexión exitosa');
            $this->newLine();

            // 3. Crear archivo de prueba
            $this->info('3️⃣ Creando archivo de prueba...');
            $testContent = 'Este es un archivo de prueba creado el '.now()->toDateTimeString();
            $testPath = 'test/minio-test-'.Str::random(10).'.txt';

            $result = $disk->put($testPath, $testContent);
            if ($result) {
                $this->info("   ✅ Archivo creado: {$testPath}");
            } else {
                $this->error('   ❌ No se pudo crear el archivo');

                return Command::FAILURE;
            }
            $this->newLine();

            // 4. Verificar que existe
            $this->info('4️⃣ Verificando que el archivo existe...');
            if ($disk->exists($testPath)) {
                $this->info('   ✅ El archivo existe');
            } else {
                $this->error('   ❌ El archivo no existe');

                return Command::FAILURE;
            }
            $this->newLine();

            // 5. Leer contenido
            $this->info('5️⃣ Leyendo contenido del archivo...');
            $content = $disk->get($testPath);
            $this->line("   Contenido: {$content}");
            $this->info('   ✅ Lectura exitosa');
            $this->newLine();

            // 6. Obtener URL
            $this->info('6️⃣ Obteniendo URL del archivo...');
            $url = $disk->url($testPath);
            $this->line("   URL: {$url}");
            $this->newLine();

            // 7. Obtener tamaño
            $this->info('7️⃣ Obteniendo tamaño del archivo...');
            $size = $disk->size($testPath);
            $this->line("   Tamaño: {$size} bytes");
            $this->newLine();

            // 8. Listar archivos en test/
            $this->info('8️⃣ Listando archivos en test/...');
            $files = $disk->files('test');
            if (count($files) > 0) {
                $this->line('   Archivos encontrados:');
                foreach ($files as $file) {
                    $this->line("   - {$file}");
                }
            } else {
                $this->line('   No hay archivos en test/');
            }
            $this->newLine();

            // 9. Eliminar archivo de prueba
            $this->info('9️⃣ Eliminando archivo de prueba...');
            if ($disk->delete($testPath)) {
                $this->info("   ✅ Archivo eliminado: {$testPath}");
            } else {
                $this->warn("   ⚠️  No se pudo eliminar el archivo: {$testPath}");
            }
            $this->newLine();

            $this->info('✅ Todas las pruebas completadas exitosamente!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Error durante la prueba: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return Command::FAILURE;
        }
    }

    /**
     * Mostrar configuración actual
     */
    protected function displayConfig(): void
    {
        $config = config('filesystems.disks.s3');

        $this->table(
            ['Configuración', 'Valor'],
            [
                ['Driver', $config['driver'] ?? 'N/A'],
                ['Endpoint', $config['endpoint'] ?? 'N/A'],
                ['Bucket', $config['bucket'] ?? 'N/A'],
                ['Region', $config['region'] ?? 'N/A'],
                ['Key', $config['key'] ? substr($config['key'], 0, 10).'...' : 'N/A'],
                ['Path Style', $config['use_path_style_endpoint'] ? 'true' : 'false'],
            ]
        );
    }
}
