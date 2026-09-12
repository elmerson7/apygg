<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Log;

/**
 * LlmAgent
 *
 * Bucle multi-turno con function calling: el modelo pide herramientas, se
 * ejecutan, se le devuelven los resultados y se repite hasta que responde o se
 * agotan las iteraciones.
 *
 * El agente nunca ejecuta nada por sí mismo: quien lo llama inyecta el
 * ejecutor. Eso mantiene la decisión de qué puede tocar el modelo fuera de esta
 * clase, que es donde debe estar.
 *
 * Delega el transporte en LlmGateway, así que hereda feature flags, circuit
 * breaker, fallbacks y auditoría.
 *
 * Uso:
 *   $registry = app(ToolRegistry::class);
 *
 *   $resultado = app(LlmAgent::class)
 *       ->useCase('docs.consulta')
 *       ->module('docs')
 *       ->run(
 *           messages: [['role' => 'user', 'content' => $pregunta]],
 *           tools: $registry->toOpenAiTools('admin'),
 *           executor: fn (string $n, array $args) => $registry->executeByName($n, $args, 'admin'),
 *       );
 */
class LlmAgent
{
    public function __construct(protected LlmGateway $gateway) {}

    /**
     * Caso de uso de esta ejecución. Se propaga al gateway, que es quien
     * resuelve el área, el feature flag y la auditoría.
     */
    public function useCase(string $key): self
    {
        $this->gateway->useCase($key);

        return $this;
    }

    /**
     * Módulo que origina la ejecución; solo se usa para la auditoría.
     */
    public function module(string $module): self
    {
        $this->gateway->module($module);

        return $this;
    }

    /**
     * Metadatos extra para la auditoría.
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->gateway->withMeta($meta);

        return $this;
    }

    /**
     * Ejecutar el bucle hasta obtener una respuesta final o agotar iteraciones.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools  Definiciones estilo OpenAI
     * @param  callable(string, array<string, mixed>): mixed  $executor
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, message: array<string, mixed>|null, error: string|null, toolCalls: int}
     */
    public function run(
        array $messages,
        array $tools,
        callable $executor,
        ?int $maxIterations = null,
        array $options = []
    ): array {
        $maxIterations ??= $this->gateway->maxIterations($options);
        $options = array_merge($options, ['tools' => $tools]);

        if (! empty($tools)) {
            $options['tool_choice'] ??= 'auto';
        }

        $toolCalls = 0;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $response = $this->gateway->chat($messages, $options);

            if (! $response->isOk()) {
                return [
                    'ok' => false,
                    'message' => null,
                    'error' => $response->error,
                    'toolCalls' => $toolCalls,
                ];
            }

            $message = $response->raw['choices'][0]['message'] ?? null;

            if (! is_array($message)) {
                return [
                    'ok' => false,
                    'message' => null,
                    'error' => 'respuesta del LLM inválida',
                    'toolCalls' => $toolCalls,
                ];
            }

            $pending = $message['tool_calls'] ?? [];

            // Sin llamadas a herramientas, esta es la respuesta final.
            if (empty($pending)) {
                return [
                    'ok' => true,
                    'message' => $message,
                    'error' => null,
                    'toolCalls' => $toolCalls,
                ];
            }

            $messages[] = $message;

            foreach ($pending as $toolCall) {
                $name = (string) ($toolCall['function']['name'] ?? '');
                $arguments = $toolCall['function']['arguments'] ?? '{}';
                $args = is_string($arguments) ? json_decode($arguments, true) : $arguments;
                $args = is_array($args) ? $args : [];

                $toolCalls++;

                try {
                    $result = $executor($name, $args);
                } catch (\Throwable $e) {
                    // El error se le devuelve al modelo en lugar de abortar:
                    // suele corregir los argumentos y reintentar por su cuenta.
                    Log::warning('LlmAgent: fallo al ejecutar una herramienta', [
                        'tool' => $name,
                        'error' => $e->getMessage(),
                    ]);

                    $result = ['error' => $e->getMessage()];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                    'content' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return [
            'ok' => false,
            'message' => null,
            'error' => 'se alcanzó el máximo de iteraciones',
            'toolCalls' => $toolCalls,
        ];
    }
}
