<?php

namespace Tests\Support;

use App\Contracts\MultipartStorageInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Subida por partes falsa sobre un disco local: las partes viven en `<path>.mp/<uploadId>/<n>` y `complete`
 * las concatena en `<path>`. Sirve entre procesos (los hijos de ConcurrentRunner comparten disco).
 * Fallos inyectables: `$failAbort`, `$failComplete`.
 */
class FakeMultipartStorage implements MultipartStorageInterface
{
    public bool $failAbort = false;

    public bool $failComplete = false;

    public int $completeCalls = 0;

    public function __construct(private readonly string $supportedDisk = 'local') {}

    public function supports(string $disk): bool
    {
        return $disk === $this->supportedDisk;
    }

    public function initiate(string $disk, string $path, string $mimeType): string
    {
        $id = 'up-'.bin2hex(random_bytes(6));
        Storage::disk($disk)->put("{$path}.mp/{$id}/.meta", $mimeType);

        return $id;
    }

    public function presignPart(string $disk, string $path, string $uploadId, int $partNumber, \DateTimeInterface $expiresAt): array
    {
        return ['url' => "https://bucket.test/{$path}?partNumber={$partNumber}&uploadId={$uploadId}", 'headers' => []];
    }

    public function listParts(string $disk, string $path, string $uploadId): ?array
    {
        $dir = "{$path}.mp/{$uploadId}";
        if (! Storage::disk($disk)->exists("{$dir}/.meta")) {
            return null;
        }

        $parts = [];
        foreach (Storage::disk($disk)->files($dir) as $file) {
            $n = basename($file);
            if (ctype_digit($n)) {
                $parts[(int) $n] = ['etag' => md5((string) Storage::disk($disk)->get($file)), 'size' => (int) Storage::disk($disk)->size($file)];
            }
        }
        ksort($parts);

        return $parts;
    }

    public function complete(string $disk, string $path, string $uploadId, array $etags): void
    {
        $this->completeCalls++;
        if ($this->failComplete) {
            throw new \RuntimeException('fallo simulado al unir');
        }

        $dir = "{$path}.mp/{$uploadId}";
        if (! Storage::disk($disk)->exists("{$dir}/.meta")) {
            throw new \RuntimeException('NoSuchUpload');
        }

        ksort($etags);
        $body = '';
        foreach (array_keys($etags) as $n) {
            $body .= Storage::disk($disk)->get("{$dir}/{$n}");
        }
        Storage::disk($disk)->put($path, $body);
        Storage::disk($disk)->deleteDirectory($dir);
    }

    public function abort(string $disk, string $path, string $uploadId): void
    {
        if ($this->failAbort) {
            throw new \RuntimeException('fallo simulado al abortar');
        }

        Storage::disk($disk)->deleteDirectory("{$path}.mp/{$uploadId}");
    }

    /** Simula el PUT del cliente de una parte. */
    public function putPart(string $disk, string $path, string $uploadId, int $partNumber, string $content): void
    {
        Storage::disk($disk)->put("{$path}.mp/{$uploadId}/{$partNumber}", $content);
    }
}
