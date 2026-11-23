<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsTypedInput;
use App\Models\Llm\LlmArea;
use App\Services\LLM\LlmGateway;
use Illuminate\Console\Command;

/**
 * LlmTestCommand
 *
 * Envía un prompt de prueba a un área para verificar credenciales, modelo y
 * conectividad sin disparar un flujo real de la aplicación.
 *
 * Uso:
 *   php artisan llm:test soporte
 *   php artisan llm:test soporte --prompt="Resume en una línea qué es Laravel"
 */
class LlmTestCommand extends Command
{
    use ReadsTypedInput;

    protected $signature = 'llm:test
                            {area : Clave del área a probar}
                            {--prompt= : Prompt a enviar}';

    protected $description = 'Probar un área de IA con un prompt';

    public function handle(LlmGateway $gateway): int
    {
        $key = $this->stringArgument('area') ?? '';
        $area = LlmArea::query()->where('key', $key)->first();

        if (! $area) {
            $this->error("No existe el área '{$key}'.");
            $this->line('Áreas disponibles: '.(LlmArea::query()->pluck('key')->implode(', ') ?: 'ninguna'));

            return Command::FAILURE;
        }

        if (! $area->isUsable()) {
            $this->error("El área '{$key}' está inactiva o no tiene modelo configurado.");

            return Command::FAILURE;
        }

        $prompt = ($this->stringOption('prompt') ?: 'Responde únicamente con la palabra: ok');

        $this->line('Área:   '.$area->name);
        $this->line('Cadena: '.collect($area->chain())->map(fn ($e) => $e['model'])->implode(' → '));
        $this->line('Prompt: '.$prompt);
        $this->newLine();

        $response = $gateway
            ->useCase($area->key.'.test')
            ->module('cli')
            ->chat([['role' => 'user', 'content' => $prompt]]);

        if (! $response->isOk()) {
            $this->error('Sin respuesta: '.$response->error);
            $this->line('Motivo: '.$response->skipReason);

            return Command::FAILURE;
        }

        $this->info('✓ Respuesta de '.$response->model);
        $this->line($response->content);
        $this->newLine();
        $this->line(sprintf(
            'Tokens: %d entrada + %d salida = %d',
            $response->promptTokens,
            $response->completionTokens,
            $response->totalTokens()
        ));

        return Command::SUCCESS;
    }
}
