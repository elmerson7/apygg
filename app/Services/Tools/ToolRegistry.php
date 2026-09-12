<?php

namespace App\Services\Tools;

use Illuminate\Support\Collection;

/**
 * ToolRegistry
 *
 * Catálogo de herramientas disponibles para el LLM, filtrado por scope.
 *
 * Las clases se declaran en `config/llm.php` → `tools`, de modo que añadir una
 * herramienta no obliga a tocar esta clase. El boilerplate no trae ninguna:
 * las herramientas dependen del dominio de cada proyecto.
 */
class ToolRegistry
{
    /** @var array<string, BaseTool> */
    protected array $tools = [];

    public function __construct()
    {
        $this->loadTools();
    }

    /**
     * Herramientas disponibles para un scope.
     *
     * @return Collection<int, BaseTool>
     */
    public function getForScope(string $scope): Collection
    {
        return collect($this->tools)
            ->filter(fn (BaseTool $tool) => $tool->canUse($scope))
            ->values();
    }

    /**
     * Definiciones en formato OpenAI para pasarlas al agente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOpenAiTools(string $scope): array
    {
        return $this->getForScope($scope)
            ->map(fn (BaseTool $tool) => $tool->toOpenAiTool())
            ->values()
            ->toArray();
    }

    /**
     * Ejecutar una herramienta por nombre, comprobando el scope.
     *
     * Devuelve null si no existe o si el scope no la tiene permitida: el
     * modelo puede alucinar nombres de herramientas, y ese caso debe
     * responderse, no lanzar una excepción.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public function executeByName(string $name, array $args, string $scope): ?array
    {
        $tool = $this->tools[$name] ?? null;

        if (! $tool || ! $tool->canUse($scope)) {
            return null;
        }

        return $tool->execute($args);
    }

    /**
     * ¿Hay alguna herramienta registrada?
     */
    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    protected function loadTools(): void
    {
        foreach ((array) config('llm.tools', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $instance = app($class);

            if ($instance instanceof BaseTool) {
                $this->tools[$instance->name()] = $instance;
            }
        }
    }
}
