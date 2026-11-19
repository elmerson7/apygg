<?php

namespace App\Console\Commands;

use App\OpenApi\OpenApiDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Exporta el documento OpenAPI 3.1 que genera Scramble a `docs/openapi.json` (versionado en git).
 *
 * Uso:
 *   php artisan openapi:export            # escribe docs/openapi.json
 *   php artisan openapi:export --check    # falla si el archivo versionado está desactualizado
 */
class OpenApiExportCommand extends Command
{
    protected $signature = 'openapi:export
                            {--check : No escribe: sale con error si el documento versionado está desactualizado}';

    protected $description = 'Exporta el documento OpenAPI (Scramble) a docs/openapi.json';

    public function handle(): int
    {
        $path = OpenApiDocument::path();
        $json = OpenApiDocument::render();

        if ($this->option('check')) {
            if (! File::exists($path) || File::get($path) !== $json) {
                $this->error('docs/openapi.json está desactualizado. Ejecuta: make openapi');

                return self::FAILURE;
            }

            $this->info('docs/openapi.json está al día.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json);

        $this->info('OpenAPI exportado a '.$path);

        return self::SUCCESS;
    }
}
