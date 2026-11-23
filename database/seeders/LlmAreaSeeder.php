<?php

namespace Database\Seeders;

use App\Models\Llm\LlmArea;
use Illuminate\Database\Seeder;

/**
 * LlmAreaSeeder
 *
 * Crea un área de ejemplo para que `llm:test` funcione desde el primer momento.
 *
 * Los modelos se dejan vacíos a propósito: se eligen desde el panel o con
 * LLM_DEFAULT_MODEL, porque la elección depende del presupuesto y del caso de
 * uso de cada proyecto, no del boilerplate.
 *
 * Es idempotente.
 */
class LlmAreaSeeder extends Seeder
{
    public function run(): void
    {
        // Vía config y no env(): con la configuración cacheada, env() devuelve null.
        $defaultModel = config('llm.default_model');
        $defaultProvider = (string) config('llm.default_provider', 'openrouter');

        $areas = [
            [
                'key' => 'general',
                'name' => 'General',
                'description' => 'Área por defecto para casos de uso sin área propia.',
                'sort_order' => 0,
            ],
        ];

        foreach ($areas as $area) {
            LlmArea::updateOrCreate(
                ['key' => $area['key']],
                array_merge($area, [
                    'primary_provider' => $defaultProvider,
                    'primary_model' => $defaultModel,
                    'is_active' => true,
                ])
            );
        }

        $this->command?->info('Áreas de IA registradas: '.LlmArea::count());

        if (! $defaultModel) {
            $this->command?->warn('Sin LLM_DEFAULT_MODEL: configura el modelo de cada área desde /admin/llm/areas.');
        }
    }
}
