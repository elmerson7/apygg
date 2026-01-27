<?php

namespace App\Console\Commands;

use App\Services\CacheService;
use Illuminate\Console\Command;

/**
 * CacheInvalidateCommand
 *
 * Comando para invalidar cache por tags o patrones.
 * Útil para limpieza manual o scripts de mantenimiento.
 */
class CacheInvalidateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:invalidate 
                            {--tag= : Invalidar por tag específico}
                            {--tags= : Invalidar múltiples tags separados por coma}
                            {--pattern= : Invalidar por patrón (ej: user:*)}
                            {--all : Invalidar todo el cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invalidar cache por tags, patrones o todo';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tag = $this->option('tag');
        $tags = $this->option('tags');
        $pattern = $this->option('pattern');
        $all = $this->option('all');

        if ($all) {
            return $this->invalidateAll();
        }

        if ($pattern) {
            return $this->invalidatePattern($pattern);
        }

        if ($tags) {
            return $this->invalidateTags(explode(',', $tags));
        }

        if ($tag) {
            return $this->invalidateTag($tag);
        }

        $this->error('Debes especificar --tag, --tags, --pattern o --all');

        return Command::FAILURE;
    }

    /**
     * Invalidar todo el cache
     */
    protected function invalidateAll(): int
    {
        $this->info('Invalidando todo el cache...');

        if (CacheService::flush()) {
            $this->info('✓ Todo el cache invalidado');
        } else {
            $this->error('✗ Error al invalidar cache');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Invalidar por tag
     */
    protected function invalidateTag(string $tag): int
    {
        $this->info("Invalidando cache con tag: {$tag}...");

        if (CacheService::forgetTag($tag)) {
            $this->info("✓ Cache con tag '{$tag}' invalidado");
        } else {
            $this->error("✗ Error al invalidar tag '{$tag}'");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Invalidar múltiples tags
     */
    protected function invalidateTags(array $tags): int
    {
        $this->info('Invalidando cache con tags: '.implode(', ', $tags).'...');

        if (CacheService::forgetTags($tags)) {
            $this->info('✓ Cache con tags invalidado');
        } else {
            $this->error('✗ Error al invalidar tags');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Invalidar por patrón
     */
    protected function invalidatePattern(string $pattern): int
    {
        $this->info("Invalidando cache con patrón: {$pattern}...");

        $deleted = CacheService::forgetPattern($pattern);

        if ($deleted >= 0) {
            $this->info("✓ {$deleted} elementos invalidados con patrón '{$pattern}'");
        } else {
            $this->error("✗ Error al invalidar patrón '{$pattern}'");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
