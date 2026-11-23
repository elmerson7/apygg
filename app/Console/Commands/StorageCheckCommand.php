<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Contracts\FileServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprueba los discos de archivos configurados (público y privado, sean locales,
 * S3 real o el emulador de desarrollo): sube un archivo, lo lee, lo pide por URL
 * y lo borra. Sustituye a los antiguos `test:s3` y `minio:test`.
 */
class StorageCheckCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'storage:check
        {--disk=* : Discos a comprobar (por defecto, los de config/files.php: público y privado)}';

    protected $description = 'Probar los discos de archivos: subir, leer, URL (firmada en el privado) y borrar';

    public function handle(): int
    {
        $disks = $this->listOption('disk') ?: array_values(array_unique([
            (string) config('files.disks.public'),
            (string) config('files.disks.private'),
        ]));

        $failed = false;
        foreach ($disks as $disk) {
            $private = $disk === config('files.disks.private')
                || config("filesystems.disks.{$disk}.visibility") === 'private';
            $failed = ! $this->checkDisk($disk, $private) || $failed;
        }

        $failed
            ? $this->error('Alguna comprobación de almacenamiento falló.')
            : $this->info('Almacenamiento correcto.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDisk(string $name, bool $private): bool
    {
        $config = config("filesystems.disks.{$name}");
        if (! is_array($config)) {
            $this->error("Disco '{$name}': no existe en config/filesystems.php");

            return false;
        }

        $this->line(sprintf(
            "\nDisco '%s' (%s%s)%s",
            $name,
            $config['driver'] ?? '?',
            isset($config['bucket']) ? ', bucket '.$config['bucket'] : '',
            isset($config['endpoint']) && $config['endpoint'] ? ' endpoint '.$config['endpoint'] : '',
        ));

        $disk = Storage::disk($name);
        $prefix = app(FileServiceInterface::class)->storagePrefix();
        $path = ($prefix !== '' ? $prefix.'/' : '').'storage-check/'.Str::random(12).'.txt';
        $content = 'storage:check '.now()->toIso8601String();

        try {
            if (! $disk->put($path, $content)) {
                $this->error('  subir: no se pudo escribir');

                return false;
            }
            $this->info('  subir: ok');

            if ($disk->get($path) !== $content) {
                $this->error('  leer: el contenido no coincide');

                return false;
            }
            $this->info('  leer: ok');

            $this->checkUrl($disk, $path, $content, $private, ($config['driver'] ?? '') === 's3');

            // Subida directa presignada (POST /files/uploads): solo informa, no es obligatoria.
            $direct = $disk->providesTemporaryUploadUrls();
            $direct ? $this->info('  subida directa presignada: disponible') : $this->warn('  subida directa presignada: no disponible en este disco');
        } catch (\Throwable $e) {
            $this->error('  error: '.$e->getMessage());

            return false;
        } finally {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Nada que hacer: si el disco falló, tampoco se podrá borrar.
            }
        }

        if ($disk->exists($path)) {
            $this->error('  borrar: el archivo sigue existiendo');

            return false;
        }
        $this->info('  borrar: ok');

        return true;
    }

    /**
     * Privado en S3: URL temporal firmada, que debe devolver el contenido (falla si no).
     * En el resto (público, disco local) solo avisa si la URL no responde: puede
     * apuntar a localhost y no verse desde el contenedor.
     */
    private function checkUrl(Filesystem $disk, string $path, string $content, bool $private, bool $strict): void
    {
        try {
            $url = $private
                ? $disk->temporaryUrl($path, now()->addMinutes(2))
                : $disk->url($path);
        } catch (\Throwable $e) {
            $this->warn('  URL: este disco no genera URL ('.$e->getMessage().')');

            return;
        }

        try {
            $ok = Http::timeout(5)->get($url)->body() === $content;
        } catch (\Throwable $e) {
            $ok = false;
        }

        if ($ok) {
            $this->info('  URL '.($private ? 'firmada' : 'pública').': ok');
        } elseif ($private && $strict) {
            $this->error('  URL firmada: no devolvió el contenido ('.$url.')');

            throw new \RuntimeException('La URL firmada no es accesible');
        } else {
            $this->warn('  URL '.($private ? 'firmada' : 'pública').': no accesible desde aquí ('.$url.')');
        }
    }
}
