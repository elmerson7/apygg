<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credenciales
    |--------------------------------------------------------------------------
    |
    | Una sola credencial global para todos los modelos. Apunta a cualquier API
    | compatible con OpenAI:
    |
    |   OpenRouter  → https://openrouter.ai/api/v1   (acceso a modelos de varios proveedores)
    |   OpenAI      → https://api.openai.com/v1
    |   DeepSeek    → https://api.deepseek.com/v1
    |   Local       → http://localhost:11434/v1      (Ollama, vLLM, LM Studio…)
    |
    | Sin URL o sin API key, el gateway devuelve respuestas "skipped" y los
    | módulos que usan IA degradan sin romperse.
    |
    */

    'url' => env('LLM_URL', ''),

    'api_key' => env('LLM_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Valores por Defecto al Sembrar
    |--------------------------------------------------------------------------
    |
    | Proveedor y modelo con los que LlmAreaSeeder crea las áreas. Con
    | OpenRouter, el identificador del modelo lleva el prefijo del proveedor
    | (por ejemplo 'anthropic/claude-sonnet-5' u 'openai/gpt-4o-mini').
    |
    | Déjalo vacío y configura el modelo de cada área desde /admin/llm/areas.
    |
    */

    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openrouter'),

    'default_model' => env('LLM_DEFAULT_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Controles Operativos
    |--------------------------------------------------------------------------
    |
    | Transporte y reintentos. NO se configuran aquí los parámetros del modelo
    | (temperature, max_tokens): cada modelo trae los suyos y sobrescribirlos a
    | ciegas empeora el resultado.
    |
    |   timeout        → segundos de espera por llamada
    |   max_attempts   → reintentos al extraer JSON estructurado
    |   max_iterations → vueltas máximas del bucle de herramientas (LlmAgent)
    |
    */

    'defaults' => [
        'timeout' => env('LLM_TIMEOUT', 60),
        'max_attempts' => env('LLM_MAX_ATTEMPTS', 2),
        'max_iterations' => env('LLM_MAX_ITERATIONS', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Tras `failures` fallos consecutivos de un área, se deja de llamar al
    | proveedor durante `open_secs` segundos. Sin esto, cada request paga el
    | timeout completo mientras el proveedor está caído.
    |
    |   count_ttl → ventana en la que se acumulan los fallos
    |
    */

    'circuit' => [
        'failures' => env('LLM_CIRCUIT_FAILURES', 5),
        'open_secs' => env('LLM_CIRCUIT_OPEN_SECS', 60),
        'count_ttl' => env('LLM_CIRCUIT_COUNT_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mapa de Casos de Uso a Áreas
    |--------------------------------------------------------------------------
    |
    | Por defecto, el área se deduce del primer segmento del caso de uso:
    | `soporte.clasificar` → área `soporte`. Registra aquí solo las excepciones.
    |
    |   'informes.resumen' => 'analitica',
    |
    */

    'area_map' => [],

    /*
    |--------------------------------------------------------------------------
    | Herramientas (function calling)
    |--------------------------------------------------------------------------
    |
    | Clases que extienden App\Services\Tools\BaseTool, disponibles para el
    | agente. Dependen del dominio de cada proyecto, así que el boilerplate no
    | trae ninguna.
    |
    |   \App\Services\Tools\BuscarUsuarioPorNombre::class,
    |
    */

    'tools' => [],

    /*
    |--------------------------------------------------------------------------
    | Retención de la Auditoría
    |--------------------------------------------------------------------------
    |
    | Días que se conservan los registros de `logs_llm`. El comando
    | `llm:prune-logs` aplica esta política.
    |
    */

    'logs_retention_days' => env('LLM_LOGS_RETENTION_DAYS', 90),

];
