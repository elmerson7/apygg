<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LlmProvider;
use App\Services\LLM\LlmProviderException;
use App\Services\LLM\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * AbstractHttpProvider
 *
 * Base para proveedores que hablan la API de chat de OpenAI
 * (`/chat/completions`, `/embeddings`): OpenAI, OpenRouter, DeepSeek, Groq,
 * Together, vLLM y prácticamente cualquier servidor local.
 *
 * Cada proveedor concreto solo declara sus particularidades en
 * `defaultOptions()`.
 *
 * Deliberadamente NO se fuerzan `temperature` ni `max_tokens`: cada modelo trae
 * sus propios valores por defecto y sobrescribirlos empeora el resultado. Solo
 * se envían si la llamada los especifica.
 */
abstract class AbstractHttpProvider implements LlmProvider
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $model,
    ) {}

    /**
     * Opciones propias del proveedor.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaultOptions(): array;

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    protected function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Un timeout o un fallo de conexión es un error del proveedor igual que un
     * HTTP 5xx: así el gateway lo registra, prueba el siguiente modelo de la
     * cadena y no revienta al consumidor con una excepción de transporte.
     *
     * @param  callable(): Response  $request
     */
    protected function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new LlmProviderException('Error de conexión con el proveedor LLM: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildPayload(array $messages, array $options): array
    {
        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
        ], $this->defaultOptions(), $options['extra'] ?? []);

        foreach (['temperature', 'max_tokens'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $payload[$key] = $key === 'temperature' ? (float) $options[$key] : (int) $options[$key];
            }
        }

        // Function calling: el gateway reenvía tools y tool_choice.
        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
            // Varios proveedores rechazan response_format junto con tools.
            unset($payload['response_format']);
        }

        return $payload;
    }

    public function chat(array $messages, array $options = []): LlmResponse
    {
        $payload = $this->buildPayload($messages, $options);

        $response = $this->send(fn () => Http::timeout((int) ($options['timeout'] ?? 60))
            ->withHeaders($this->headers())
            ->post($this->endpoint('chat/completions'), $payload));

        if (! $response->successful()) {
            throw new LlmProviderException(
                'El proveedor LLM devolvió HTTP '.$response->status(),
                $response->status()
            );
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            throw new LlmProviderException('El proveedor LLM no devolvió ningún mensaje');
        }

        $content = (string) ($message['content'] ?? '');

        // Algunos modelos de razonamiento consumen todo el límite de tokens en
        // el razonamiento y devuelven contenido vacío: se trata como fallo para
        // que el gateway reintente o pase al siguiente modelo.
        if ($content === '' && isset($message['reasoning_content'])) {
            throw new LlmProviderException('El proveedor LLM devolvió contenido vacío (el razonamiento consumió los tokens)');
        }

        $usage = $data['usage'] ?? [];

        return new LlmResponse(
            content: $content,
            model: (string) ($data['model'] ?? $this->model),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            success: true,
            toolCalls: $message['tool_calls'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
            raw: is_array($data) ? $data : [],
        );
    }

    public function chatStream(array $messages, callable $onChunk, array $options = []): void
    {
        $payload = array_merge($this->buildPayload($messages, $options), ['stream' => true]);

        // Mismo transporte que chat() (cliente HTTP de Laravel, no Guzzle a
        // pelo): así el stream respeta timeouts, se puede fakear en tests y un
        // fallo levanta excepción para que el gateway pruebe el fallback.
        $response = $this->send(fn () => Http::withOptions(['stream' => true])
            ->timeout((int) ($options['timeout'] ?? 60))
            ->withHeaders($this->headers())
            ->post($this->endpoint('chat/completions'), $payload));

        if (! $response->successful()) {
            throw new LlmProviderException(
                'El proveedor LLM devolvió HTTP '.$response->status(),
                $response->status()
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            $this->drainSseBuffer($buffer, $onChunk);
        }

        $this->drainSseBuffer($buffer, $onChunk, true);
    }

    /**
     * Consume las líneas completas del buffer SSE y emite los deltas de
     * contenido, dejando la línea parcial pendiente: el proveedor puede cortar
     * un chunk a mitad de línea.
     *
     * @param  callable(string): void  $onChunk
     */
    protected function drainSseBuffer(string &$buffer, callable $onChunk, bool $flush = false): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $this->emitSseLine($line, $onChunk);
        }

        if ($flush && trim($buffer) !== '') {
            $this->emitSseLine($buffer, $onChunk);
            $buffer = '';
        }
    }

    /**
     * @param  callable(string): void  $onChunk
     */
    protected function emitSseLine(string $line, callable $onChunk): void
    {
        $line = trim($line);

        if ($line === '' || $line === 'data: [DONE]' || ! str_starts_with($line, 'data: ')) {
            return;
        }

        $json = json_decode(substr($line, 6), true);
        $delta = is_array($json) ? ($json['choices'][0]['delta']['content'] ?? '') : '';

        if (is_string($delta) && $delta !== '') {
            $onChunk($delta);
        }
    }

    public function embed(string $text, array $options = []): ?array
    {
        $payload = array_merge([
            'model' => $options['model'] ?? $this->model,
            'input' => $text,
        ], $options['extra'] ?? []);

        $response = $this->send(fn () => Http::timeout((int) ($options['timeout'] ?? 30))
            ->withHeaders($this->headers())
            ->post($this->endpoint('embeddings'), $payload));

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data.0.embedding');
    }
}
