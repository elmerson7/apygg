<?php

namespace App\Http\Controllers\Llm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Llm\StoreLlmAreaRequest;
use App\Http\Requests\Llm\UpdateLlmAreaRequest;
use App\Http\Resources\Llm\LlmAreaResource;
use App\Models\Llm\LlmArea;
use App\Services\LLM\LlmGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LlmAreaController
 *
 * Gestión de las áreas de IA: qué modelo usa cada una y con qué fallbacks.
 * Permite cambiar de modelo sin desplegar.
 */
class LlmAreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $areas = LlmArea::query()
            ->orderBy('sort_order')
            ->paginate($this->perPage($request, 25));

        return $this->sendPaginated($areas);
    }

    public function store(StoreLlmAreaRequest $request): JsonResponse
    {
        $area = LlmArea::create($request->validated());

        return $this->sendSuccess(new LlmAreaResource($area), 'Área creada exitosamente', 201);
    }

    public function show(LlmArea $llmArea): JsonResponse
    {
        return $this->sendSuccess(new LlmAreaResource($llmArea), 'Área obtenida exitosamente');
    }

    public function update(UpdateLlmAreaRequest $request, LlmArea $llmArea): JsonResponse
    {
        $llmArea->update($request->validated());

        return $this->sendSuccess(new LlmAreaResource($llmArea), 'Área actualizada exitosamente');
    }

    public function destroy(LlmArea $llmArea): JsonResponse
    {
        $llmArea->delete();

        return $this->sendSuccess(null, 'Área eliminada exitosamente');
    }

    /**
     * Estado operativo de la capa LLM: credenciales, áreas utilizables y
     * herramientas registradas. Es lo primero que hay que mirar cuando "la IA
     * no responde".
     */
    public function status(): JsonResponse
    {
        $areas = LlmArea::query()->orderBy('sort_order')->get();

        return $this->sendSuccess([
            'configured' => (string) config('llm.url') !== '' && (string) config('llm.api_key') !== '',
            'url' => config('llm.url') ?: null,
            'defaults' => config('llm.defaults'),
            'circuit' => config('llm.circuit'),
            'tools_registered' => count((array) config('llm.tools', [])),
            'areas' => $areas->map(fn (LlmArea $area) => [
                'key' => $area->key,
                'name' => $area->name,
                'is_active' => $area->is_active,
                'usable' => $area->isUsable(),
                'chain' => $area->chain(),
            ]),
        ], 'Estado de la capa LLM obtenido exitosamente');
    }

    /**
     * Probar un área con un prompt mínimo. Confirma credenciales, modelo y
     * conectividad de una sola vez.
     */
    public function test(Request $request, LlmArea $llmArea, LlmGateway $gateway): JsonResponse
    {
        $prompt = $request->string('prompt', 'Responde únicamente con la palabra: ok')->toString();

        $response = $gateway
            ->useCase($llmArea->key.'.test')
            ->module('llm-admin')
            ->chat([['role' => 'user', 'content' => $prompt]]);

        if (! $response->isOk()) {
            return $this->sendError(
                'El área no respondió: '.$response->error,
                422,
                ['skip_reason' => $response->skipReason]
            );
        }

        return $this->sendSuccess([
            'model' => $response->model,
            'content' => $response->content,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'total_tokens' => $response->totalTokens(),
        ], 'El área respondió correctamente');
    }
}
