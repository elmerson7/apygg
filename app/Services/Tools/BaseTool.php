<?php

namespace App\Services\Tools;

/**
 * BaseTool
 *
 * Herramienta que el LLM puede invocar (function calling). Cada una declara su
 * nombre, su descripción, el esquema de sus parámetros y qué hace.
 *
 * La descripción no es documentación: es lo que el modelo lee para decidir
 * cuándo usarla. Una descripción vaga produce herramientas que no se llaman o
 * que se llaman a destiempo.
 *
 * `allowedScopes` limita quién puede usarla: el mismo agente expuesto a un
 * administrador y a un usuario final no debe ofrecer el mismo conjunto.
 */
abstract class BaseTool
{
    /**
     * Scopes que pueden usar esta herramienta. Vacío = todos.
     *
     * @var array<int, string>
     */
    protected array $allowedScopes = ['admin'];

    /**
     * Nombre único, tal y como lo invocará el modelo.
     */
    abstract public function name(): string;

    /**
     * Descripción para el modelo: esto decide cuándo se usa.
     */
    abstract public function description(): string;

    /**
     * Esquema de parámetros en formato JSON Schema.
     *
     * @return array<string, mixed>
     */
    abstract public function parameters(): array;

    /**
     * Ejecutar la herramienta y devolver los datos.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    abstract public function execute(array $args): array;

    /**
     * ¿Puede este scope usar la herramienta?
     */
    public function canUse(string $scope): bool
    {
        return $this->allowedScopes === [] || in_array($scope, $this->allowedScopes, true);
    }

    /**
     * Definición en el formato que esperan los proveedores compatibles con OpenAI.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameters(),
            ],
        ];
    }

    /**
     * Validar que un valor sea un UUID.
     */
    protected function isValidUuid(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Validar que un valor sea un id entero positivo.
     */
    protected function isValidId(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }
}
