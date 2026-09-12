<?php

namespace App\Services\LLM\Contracts;

use App\Services\LLM\LlmResponse;

/**
 * LlmProvider
 *
 * Contrato común para cualquier proveedor de LLM (OpenAI, OpenRouter, DeepSeek,
 * un modelo local…). Solo `LlmGateway` conoce esta interfaz: los servicios de
 * la aplicación nunca instancian un proveedor directamente.
 *
 * Añadir un proveedor nuevo = una clase que implemente esto (o que extienda
 * AbstractHttpProvider si habla la API de OpenAI) y una entrada en el `match`
 * de LlmGateway::buildProvider().
 */
interface LlmProvider
{
    /**
     * Llamada de chat estándar, sin streaming.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): LlmResponse;

    /**
     * Llamada de chat con streaming: se invoca `$onChunk` por cada fragmento.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  callable(string): void  $onChunk
     * @param  array<string, mixed>  $options
     */
    public function chatStream(array $messages, callable $onChunk, array $options = []): void;

    /**
     * Generar el embedding de un texto.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, float>|null
     */
    public function embed(string $text, array $options = []): ?array;

    /**
     * ¿Tiene credenciales y configuración válidas?
     */
    public function isConfigured(): bool;
}
