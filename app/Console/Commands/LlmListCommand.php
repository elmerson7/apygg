<?php

namespace App\Console\Commands;

use App\Models\Llm\LlmArea;
use Illuminate\Console\Command;

/**
 * LlmListCommand
 *
 * Muestra el estado de la capa de IA: credenciales, áreas y su cadena de
 * modelos. Es el primer comando que ejecutar cuando "la IA no responde".
 *
 * Uso: php artisan llm:list
 */
class LlmListCommand extends Command
{
    protected $signature = 'llm:list';

    protected $description = 'Mostrar el estado de la capa LLM y las áreas configuradas';

    public function handle(): int
    {
        $url = (string) config('llm.url');
        $apiKey = (string) config('llm.api_key');
        $configured = $url !== '' && $apiKey !== '';

        $this->info('Capa LLM');
        $this->table(['Propiedad', 'Valor'], [
            ['URL', $url ?: '(sin configurar)'],
            ['API key', $apiKey !== '' ? '••• configurada' : '(sin configurar)'],
            ['Timeout', config('llm.defaults.timeout').'s'],
            ['Reintentos JSON', config('llm.defaults.max_attempts')],
            ['Iteraciones agente', config('llm.defaults.max_iterations')],
            ['Circuit breaker', config('llm.circuit.failures').' fallos → '.config('llm.circuit.open_secs').'s abierto'],
            ['Herramientas', count((array) config('llm.tools', []))],
        ]);

        if (! $configured) {
            $this->warn('⚠️  Sin LLM_URL o LLM_API_KEY: la IA está desactivada y los módulos que la usan degradan sin romperse.');
        }

        $areas = LlmArea::query()->orderBy('sort_order')->get();

        if ($areas->isEmpty()) {
            $this->newLine();
            $this->warn('No hay áreas configuradas.');
            $this->line('Créalas con: php artisan db:seed --class=LlmAreaSeeder');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Áreas');
        $this->table(
            ['Clave', 'Nombre', 'Activa', 'Usable', 'Cadena de modelos'],
            $areas->map(fn (LlmArea $area) => [
                $area->key,
                $area->name,
                $area->is_active ? 'sí' : 'no',
                $area->isUsable() ? '✓' : '✗',
                collect($area->chain())->map(fn ($e) => "{$e['provider']}:{$e['model']}")->implode(' → ') ?: '(sin modelo)',
            ])->toArray()
        );

        return Command::SUCCESS;
    }
}
