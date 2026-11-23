<?php

namespace App\Services\LLM;

use App\Models\Llm\LlmArea;
use App\Services\LLM\Contracts\LlmProvider;
use App\Services\LLM\Providers\DeepSeekProvider;
use App\Services\LLM\Providers\NullProvider;
use App\Services\LLM\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * LlmGateway
 *
 * Punto de entrada único al LLM. Ningún servicio debe hablar directamente con
 * un proveedor: todos pasan por aquí, que se encarga de
 *
 *  - resolver el área del caso de uso y probar su cadena de modelos
 *    (principal + fallbacks) hasta que uno responda,
 *  - respetar el feature flag del caso de uso,
 *  - abrir un circuit breaker cuando un área falla de forma repetida,
 *  - extraer salidas estructuradas (JSON) con reintentos, y
 *  - auditar cada llamada.
 *
 * Uso:
 *   $respuesta = app(LlmGateway::class)
 *       ->useCase('soporte.clasificar')
 *       ->module('tickets')
 *       ->chat([['role' => 'user', 'content' => $texto]]);
 *
 *   if ($respuesta->isOk()) { ... }
 *
 * La respuesta nunca es null: si el LLM no está configurado o el circuito está
 * abierto, se devuelve una respuesta "skipped" y el módulo degrada sin romper.
 */
class LlmGateway
{
    /**
     * Controles de transporte y reintentos que este gateway sí gestiona. Los
     * parámetros del modelo (temperature, max_tokens) no se tocan: cada modelo
     * trae los suyos.
     *
     * @var array<int, string>
     */
    private const PARAM_KEYS = ['timeout', 'max_attempts', 'max_iterations'];

    protected ?string $useCase = null;

    protected ?string $module = null;

    /** @var array<string, mixed> */
    protected array $meta = [];

    public function __construct(protected LlmAuditService $audit) {}

    /**
     * Caso de uso de esta llamada, en formato `area.accion`
     * (por ejemplo `soporte.clasificar`).
     */
    public function useCase(string $key): self
    {
        $this->useCase = $key;

        return $this;
    }

    /**
     * Módulo que origina la llamada; solo se usa para la auditoría.
     */
    public function module(string $module): self
    {
        $this->module = $module;

        return $this;
    }

    /**
     * Metadatos extra para la auditoría (ids de entidad, contexto…).
     *
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Clave de área del caso de uso actual.
     *
     * Se resuelve con `config('llm.area_map')` y, si no hay entrada, con el
     * primer segmento del caso de uso: `soporte.clasificar` → `soporte`. Esa
     * convención evita tener que registrar cada caso de uso a mano.
     */
    public function areaKey(): ?string
    {
        if ($this->useCase === null) {
            return null;
        }

        // Se lee el array completo y se indexa a mano: las claves del mapa
        // llevan punto ('informes.resumen') y config() las interpretaría como
        // rutas anidadas, con lo que nunca encontraría la entrada.
        $map = (array) config('llm.area_map', []);
        $mapped = $map[$this->useCase] ?? null;

        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }

        return str_contains($this->useCase, '.')
            ? explode('.', $this->useCase, 2)[0]
            : $this->useCase;
    }

    /**
     * Área activa y con al menos un modelo configurado, o null.
     */
    public function area(): ?LlmArea
    {
        $key = $this->areaKey();

        if ($key === null) {
            return null;
        }

        $area = LlmArea::query()->where('key', $key)->active()->first();

        return $area?->isUsable() ? $area : null;
    }

    /**
     * Controles operativos: defaults de config, sobrescritos por las opciones.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function llmParams(array $options = []): array
    {
        $merged = (array) config('llm.defaults', []);

        foreach (self::PARAM_KEYS as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $merged[$key] = $options[$key];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function maxAttempts(array $options = []): int
    {
        return (int) ($this->llmParams($options)['max_attempts'] ?? 2);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function maxIterations(array $options = []): int
    {
        return (int) ($this->llmParams($options)['max_iterations'] ?? 6);
    }

    /**
     * Construir el proveedor de una entrada de la cadena.
     *
     * @param  array{provider: string, model: string}  $entry
     */
    protected function buildProvider(array $entry): LlmProvider
    {
        if (! $this->credentialsConfigured()) {
            return new NullProvider;
        }

        $url = (string) config('llm.url');
        $apiKey = (string) config('llm.api_key');

        return match ($entry['provider']) {
            'deepseek' => new DeepSeekProvider($url, $apiKey, $entry['model']),
            // openai, openrouter y cualquier pasarela compatible
            default => new OpenAiProvider($url, $apiKey, $entry['model']),
        };
    }

    protected function credentialsConfigured(): bool
    {
        return (string) config('llm.url') !== '' && (string) config('llm.api_key') !== '';
    }

    /**
     * Feature flag por caso de uso. Sin configuración, se asume habilitado.
     */
    protected function useCaseEnabled(): bool
    {
        if ($this->useCase === null) {
            return true;
        }

        $config = config("features.llm.{$this->useCase}");

        return $config === null ? true : (bool) ($config['enabled'] ?? true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Circuit breaker por área
    // ─────────────────────────────────────────────────────────────────────

    protected function circuitKey(): string
    {
        return 'llm:cb:'.($this->areaKey() ?? 'desconocida');
    }

    protected function circuitOpen(): bool
    {
        $until = Cache::get($this->circuitKey().':open');

        return $until !== null && $until > time();
    }

    /**
     * Tras N fallos seguidos se abre el circuito durante unos segundos. Evita
     * castigar cada request con el timeout completo mientras el proveedor está
     * caído, y da margen a que se recupere.
     */
    protected function recordFailure(): void
    {
        $key = $this->circuitKey();
        $threshold = (int) config('llm.circuit.failures', 5);
        $openSecs = (int) config('llm.circuit.open_secs', 60);
        $countTtl = (int) config('llm.circuit.count_ttl', 300);

        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, $countTtl);

        if ($failures >= $threshold) {
            Cache::put($key.':open', time() + $openSecs, $openSecs);
            Cache::forget($key);

            Log::warning('LLM: circuit breaker ABIERTO', [
                'use_case' => $this->useCase,
                'area' => $this->areaKey(),
                'open_secs' => $openSecs,
            ]);
        }
    }

    protected function recordSuccess(): void
    {
        Cache::forget($this->circuitKey());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Llamadas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): LlmResponse
    {
        if (! $this->useCaseEnabled()) {
            return LlmResponse::skipped(
                "feature flag apagado para {$this->useCase}",
                LlmResponse::SKIP_FLAG_DISABLED,
            );
        }

        if ($this->circuitOpen()) {
            return LlmResponse::skipped('circuit breaker abierto', LlmResponse::SKIP_CIRCUIT_OPEN);
        }

        $area = $this->area();

        if ($area === null || ! $this->credentialsConfigured()) {
            $this->logUnavailable($area);

            return LlmResponse::skipped('LLM no configurado', LlmResponse::SKIP_NOT_CONFIGURED);
        }

        $params = $this->llmParams($options);
        $options = array_merge($options, ['timeout' => $params['timeout'] ?? 60]);

        $lastError = 'LLM no configurado';

        foreach ($area->chain() as $entry) {
            try {
                $response = $this->buildProvider($entry)->chat($messages, $options);

                $this->recordSuccess();
                $this->recordAudit($messages, $response, null);

                return $response;
            } catch (LlmProviderException $e) {
                $this->recordFailure();
                $this->recordAudit($messages, null, $e->getMessage());
                $lastError = $e->getMessage();
            }
        }

        return LlmResponse::skipped($lastError, LlmResponse::SKIP_PROVIDER_ERROR);
    }

    /**
     * Chat con salida JSON estructurada y reintentos si el formato no es válido.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<mixed>|null
     */
    public function structured(array $messages, array $options = [], ?int $maxAttempts = null): ?array
    {
        $maxAttempts ??= $this->maxAttempts($options);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attemptMessages = $messages;

            if ($attempt > 1) {
                $attemptMessages[] = [
                    'role' => 'user',
                    'content' => 'IMPORTANTE: responde SOLO el objeto JSON válido, sin texto adicional ni markdown.',
                ];
            }

            $response = $this->chat($attemptMessages, $options);

            // Si no hay respuesta, reintentar no va a ayudar.
            if (! $response->isOk()) {
                return null;
            }

            $decoded = StructuredOutput::extract($response->content);

            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Chat con streaming.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  callable(string): void  $onChunk
     * @param  array<string, mixed>  $options
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): void
    {
        if (! $this->useCaseEnabled() || $this->circuitOpen()) {
            $onChunk('El asistente no está disponible en este momento.');

            return;
        }

        $area = $this->area();

        if ($area === null || ! $this->credentialsConfigured()) {
            $this->logUnavailable($area);
            $onChunk('El asistente no está configurado.');

            return;
        }

        $params = $this->llmParams($options);
        $options = array_merge($options, ['timeout' => $params['timeout'] ?? 60]);

        // Solo se puede cambiar de modelo mientras no se haya emitido texto:
        // reintentar a mitad del stream concatenaría dos respuestas distintas.
        $streamed = false;
        $guardedChunk = function (string $chunk) use (&$streamed, $onChunk): void {
            $streamed = true;
            $onChunk($chunk);
        };

        foreach ($area->chain() as $entry) {
            $provider = $this->buildProvider($entry);

            if (! $provider->isConfigured()) {
                continue;
            }

            try {
                $provider->chatStream($messages, $guardedChunk, $options);
                $this->recordSuccess();

                return;
            } catch (LlmProviderException $e) {
                $this->recordFailure();

                Log::warning('LLM: fallo en streaming', [
                    'use_case' => $this->useCase,
                    'model' => $entry['model'],
                    'error' => $e->getMessage(),
                ]);

                if ($streamed) {
                    return;
                }
            }
        }

        if (! $streamed) {
            $onChunk('El asistente no está disponible en este momento.');
        }
    }

    /**
     * Embedding de un texto, usando el modelo del área.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, float>|null
     */
    public function embed(string $text, array $options = []): ?array
    {
        $area = $this->area();

        if ($area === null || ! $this->credentialsConfigured()) {
            return null;
        }

        foreach ($area->chain() as $entry) {
            $embedding = $this->buildProvider($entry)->embed($text, $options);

            if ($embedding !== null) {
                return $embedding;
            }
        }

        return null;
    }

    /**
     * Un área apagada o sin modelo hace que el módulo que la usa degrade en
     * silencio. Se registra para que quede rastro de por qué no hubo IA.
     */
    protected function logUnavailable(?LlmArea $area): void
    {
        Log::warning('LLM no disponible: área sin modelo activo o credenciales ausentes', [
            'use_case' => $this->useCase,
            'area' => $this->areaKey(),
            'area_resuelta' => $area !== null,
            'credenciales' => $this->credentialsConfigured(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function recordAudit(array $messages, ?LlmResponse $response, ?string $error): void
    {
        $this->audit->record(
            useCase: $this->useCase ?? 'desconocido',
            messages: $messages,
            response: $response,
            module: $this->module,
            meta: $this->meta,
            error: $error,
        );
    }
}
