<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Scout\Searchable;
use ReflectionClass;

class ScoutManageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:manage 
                            {action : Action to perform (sync, import, flush, reset)}
                            {--model= : Specific model to process (optional)}
                            {--force : Force operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Scout indices for all searchable models';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $specificModel = $this->option('model');
        $force = $this->option('force');

        $models = $specificModel ? [$specificModel] : $this->getSearchableModels();

        if (empty($models)) {
            $this->error('No searchable models found.');
            return 1;
        }

        $this->info("Found " . count($models) . " searchable model(s):");
        foreach ($models as $model) {
            $this->line("  - {$model}");
        }

        if (!$force && !$this->confirm("Proceed with '{$action}' action?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        switch ($action) {
            case 'sync':
                return $this->syncIndexSettings();
            case 'import':
                return $this->importModels($models);
            case 'flush':
                return $this->flushModels($models);
            case 'reset':
                return $this->resetModels($models);
            default:
                $this->error("Unknown action: {$action}");
                $this->line("Available actions: sync, import, flush, reset");
                return 1;
        }
    }

    /**
     * Get all models that use the Searchable trait.
     */
    private function getSearchableModels(): array
    {
        $models = [];
        $modelPath = app_path('Models');

        if (!File::exists($modelPath)) {
            return $models;
        }

        $files = File::allFiles($modelPath);

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file->getPathname());
            
            if ($className && class_exists($className)) {
                $reflection = new ReflectionClass($className);
                
                if ($this->usesSearchableTrait($reflection)) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    /**
     * Get class name from file path.
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $relativePath = str_replace(app_path(), '', $filePath);
        $relativePath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
        
        return 'App' . $relativePath;
    }

    /**
     * Check if class uses Searchable trait.
     */
    private function usesSearchableTrait(ReflectionClass $class): bool
    {
        $traits = $class->getTraitNames();
        
        foreach ($traits as $trait) {
            if ($trait === Searchable::class || str_ends_with($trait, 'Searchable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync index settings.
     */
    private function syncIndexSettings(): int
    {
        $this->info('Syncing index settings...');
        
        $exitCode = $this->call('scout:sync-index-settings');
        
        if ($exitCode === 0) {
            $this->info('✅ Index settings synced successfully.');
        } else {
            $this->error('❌ Failed to sync index settings.');
        }

        return $exitCode;
    }

    /**
     * Import models to search index.
     */
    private function importModels(array $models): int
    {
        $this->info('Importing models to search index...');
        
        $overallSuccess = true;

        foreach ($models as $model) {
            $this->line("Importing {$model}...");
            
            $exitCode = $this->call('scout:import', ['model' => $model]);
            
            if ($exitCode === 0) {
                $this->info("  ✅ {$model} imported successfully.");
            } else {
                $this->error("  ❌ Failed to import {$model}.");
                $overallSuccess = false;
            }
        }

        if ($overallSuccess) {
            $this->info('✅ All models imported successfully.');
            return 0;
        } else {
            $this->error('❌ Some models failed to import.');
            return 1;
        }
    }

    /**
     * Flush models from search index.
     */
    private function flushModels(array $models): int
    {
        $this->info('Flushing models from search index...');
        
        $overallSuccess = true;

        foreach ($models as $model) {
            $this->line("Flushing {$model}...");
            
            $exitCode = $this->call('scout:flush', ['model' => $model]);
            
            if ($exitCode === 0) {
                $this->info("  ✅ {$model} flushed successfully.");
            } else {
                $this->error("  ❌ Failed to flush {$model}.");
                $overallSuccess = false;
            }
        }

        if ($overallSuccess) {
            $this->info('✅ All models flushed successfully.');
            return 0;
        } else {
            $this->error('❌ Some models failed to flush.');
            return 1;
        }
    }

    /**
     * Reset search index (flush + sync + import).
     */
    private function resetModels(array $models): int
    {
        $this->info('Resetting search indices (flush + sync + import)...');

        // Step 1: Flush all models
        $this->line('Step 1/3: Flushing models...');
        if ($this->flushModels($models) !== 0) {
            $this->error('❌ Failed during flush step.');
            return 1;
        }

        // Step 2: Sync index settings
        $this->line('Step 2/3: Syncing index settings...');
        if ($this->syncIndexSettings() !== 0) {
            $this->error('❌ Failed during sync step.');
            return 1;
        }

        // Step 3: Import all models
        $this->line('Step 3/3: Importing models...');
        if ($this->importModels($models) !== 0) {
            $this->error('❌ Failed during import step.');
            return 1;
        }

        $this->info('✅ Search indices reset successfully!');
        return 0;
    }
}
