# Capa LLM

Integrar un LLM en una aplicación no es "llamar a una API". Lo que cuesta es
todo lo demás: cambiar de modelo sin desplegar, no dejar el request colgado 60
segundos cuando el proveedor está caído, saber cuánto se está gastando y en qué,
y que un fallo de la IA no tumbe la operación que la invocó.

Esta capa resuelve eso. Es **agnóstica del proveedor**: habla con cualquier API
compatible con OpenAI (OpenRouter, OpenAI, DeepSeek, Groq, vLLM u Ollama en
local).

## Piezas

| Clase | Rol |
|---|---|
| `LlmGateway` | Punto de entrada único. Áreas, fallbacks, flags, circuit breaker y auditoría |
| `LlmResponse` | DTO del resultado. Nunca null: los fallos son respuestas con motivo |
| `LlmAgent` | Bucle multi-turno con function calling |
| `LlmAuditService` | Persiste cada llamada en `logs_llm` |
| `StructuredOutput` | Extrae JSON de la respuesta, aunque venga envuelto |
| `Contracts\LlmProvider` | Contrato de proveedor |
| `Providers\AbstractHttpProvider` | Base para APIs compatibles con OpenAI |
| `Tools\BaseTool` / `ToolRegistry` | Herramientas que el modelo puede invocar |

| Tabla | Qué guarda |
|---|---|
| `llm_areas` | Modelo principal y fallbacks de cada área |
| `logs_llm` | Auditoría: caso de uso, modelo, tokens, estado, actor, trace_id |

## Puesta en marcha

```bash
# .env
LLM_URL=https://openrouter.ai/api/v1
LLM_API_KEY=sk-or-...
LLM_DEFAULT_MODEL=anthropic/claude-sonnet-5
```

```bash
make art cmd="migrate"
make art cmd="db:seed --class=LlmAreaSeeder"
make art cmd="llm:list"
make art cmd="llm:test general"
```

**Sin `LLM_URL` o `LLM_API_KEY` no pasa nada malo**: el gateway devuelve
respuestas `skipped` y los módulos que usan IA degradan en silencio. Es
deliberado: un boilerplate no puede asumir que todo proyecto contrata un LLM.

## Áreas

Un **área** agrupa los casos de uso que comparten exigencia y presupuesto. Cada
una define un modelo principal y hasta cinco de respaldo:

```json
{
  "key": "soporte",
  "name": "Soporte",
  "primary_provider": "openrouter",
  "primary_model": "anthropic/claude-sonnet-5",
  "fallbacks": [
    { "provider": "openrouter", "model": "anthropic/claude-haiku-4-5" }
  ]
}
```

El **caso de uso** es la etiqueta de cada llamada, en formato `area.accion`. El
área se deduce del primer segmento: `soporte.clasificar` → área `soporte`. Para
las excepciones está `config/llm.php` → `area_map`.

Que el modelo viva en la BD y no en el código es lo que permite cambiarlo cuando
el proveedor sube precios, degrada o publica uno mejor, sin tocar una línea ni
desplegar.

## Uso

### Chat

```php
use App\Services\LLM\LlmGateway;

$respuesta = app(LlmGateway::class)
    ->useCase('soporte.clasificar')
    ->module('tickets')
    ->withMeta(['ticket_id' => $ticket->id])
    ->chat([
        ['role' => 'system', 'content' => 'Clasifica el ticket en una categoría.'],
        ['role' => 'user', 'content' => $ticket->descripcion],
    ]);

if ($respuesta->isOk()) {
    $categoria = $respuesta->content;
} elseif ($respuesta->isUnavailable()) {
    // No configurado, flag apagado o circuito abierto: no es un incidente.
    // Muestra "sin análisis", no "error de IA".
} else {
    // El proveedor falló: esto sí es un incidente.
}
```

### Salida estructurada (JSON)

```php
$datos = app(LlmGateway::class)
    ->useCase('soporte.extraer')
    ->structured([
        ['role' => 'system', 'content' => 'Devuelve {"categoria": "...", "urgencia": 1-5}'],
        ['role' => 'user', 'content' => $texto],
    ]);

// array|null. Reintenta automáticamente si la respuesta no trae JSON válido.
```

### Streaming

```php
app(LlmGateway::class)
    ->useCase('docs.chat')
    ->chatStream($mensajes, function (string $fragmento) {
        echo $fragmento;
        ob_flush();
        flush();
    });
```

Un detalle importante: si un modelo falla **después** de haber emitido texto, no
se reintenta con el siguiente. Concatenar dos respuestas distintas produce una
salida incoherente; es preferible cortar.

### Agente con herramientas

```php
$resultado = app(LlmAgent::class)
    ->useCase('docs.consulta')
    ->module('docs')
    ->run(
        messages: [['role' => 'user', 'content' => $pregunta]],
        tools: $registry->toOpenAiTools('admin'),
        executor: fn (string $n, array $args) => $registry->executeByName($n, $args, 'admin'),
    );

// ['ok' => bool, 'message' => array|null, 'error' => string|null, 'toolCalls' => int]
```

El agente **nunca ejecuta nada por sí mismo**: quien lo llama inyecta el
ejecutor. La decisión de qué puede tocar el modelo se queda fuera de la clase,
que es donde debe estar.

## Herramientas (function calling)

```php
namespace App\Services\Tools;

class BuscarUsuarioPorEmail extends BaseTool
{
    protected array $allowedScopes = ['admin'];

    public function name(): string
    {
        return 'buscar_usuario_por_email';
    }

    public function description(): string
    {
        return 'Busca un usuario por su correo exacto y devuelve su id, nombre y estado.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'description' => 'Correo exacto'],
            ],
            'required' => ['email'],
        ];
    }

    public function execute(array $args): array
    {
        $user = User::where('email', $args['email'] ?? '')->first();

        return $user
            ? ['id' => $user->id, 'name' => $user->name, 'active' => $user->isActive()]
            : ['error' => 'no encontrado'];
    }
}
```

Regístrala en `config/llm.php` → `tools`.

Dos cosas que se pasan por alto:

- **La descripción no es documentación**: es lo que el modelo lee para decidir
  cuándo usar la herramienta. Una descripción vaga produce herramientas que no
  se llaman, o que se llaman a destiempo.
- **`allowedScopes` no es decorativo**: el mismo agente expuesto a un
  administrador y a un usuario final no debe ofrecer el mismo conjunto. Valídalo
  siempre por scope, nunca por confianza en el prompt.

## Protecciones

### Feature flags

```php
// config/features.php
'llm' => [
    'soporte.clasificar' => ['enabled' => true],
    'docs.chat' => ['enabled' => false],
],
```

Sin entrada, el caso de uso se considera habilitado.

### Circuit breaker

Tras `LLM_CIRCUIT_FAILURES` fallos de un área, se deja de llamar al proveedor
durante `LLM_CIRCUIT_OPEN_SECS`. Sin esto, con el proveedor caído cada request
paga el timeout completo y la aplicación entera se arrastra.

El estado vive en caché (Redis) y es por área: que falle un área no bloquea las
demás.

### Motivos de "sin respuesta"

`LlmResponse::skipReason` distingue por qué no hubo respuesta, y la distinción
importa:

| Motivo | Significado | ¿Es un incidente? |
|---|---|---|
| `not_configured` | Sin credenciales, o el área no tiene modelo | No |
| `flag_disabled` | El caso de uso está apagado | No |
| `circuit_open` | El circuito del área está abierto | No |
| `provider_error` | El proveedor falló o no respondió | **Sí** |

`isUnavailable()` devuelve true para los tres primeros. Sin esta distinción, los
módulos muestran "error de IA" cuando en realidad nunca se configuró un modelo.

## Auditoría

Cada llamada queda en `logs_llm` con caso de uso, módulo, modelo, tokens,
estado, actor y `trace_id`. Es lo que permite responder a "¿cuánto nos está
costando la IA y qué módulo se lo gasta?".

```http
GET /admin/llm/logs?use_case=soporte.clasificar&status=error
GET /admin/llm/logs/stats?from=2026-09-01
```

La auditoría se escribe dentro de un SAVEPOINT cuando hay una transacción
abierta. En PostgreSQL, un error dentro de una transacción la deja abortada y
todas las consultas siguientes fallan: sin el savepoint, un fallo al auditar
rompería la operación de negocio que envolvía la llamada.

Prompts y respuestas se truncan a 10.000 caracteres.

## Endpoints

Bajo `/admin/llm`, con JWT. Gestión: `llm.manage`. Auditoría: `llm.logs`.

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/status` | Estado de la capa: credenciales, áreas usables, herramientas |
| GET/POST | `/areas` | Listar / crear áreas |
| GET/PUT/DELETE | `/areas/{id}` | Ver / editar / eliminar |
| POST | `/areas/{id}/test` | Probar el área con un prompt |
| GET | `/logs` | Auditoría (filtros: `use_case`, `module`, `status`, `from`, `to`) |
| GET | `/logs/stats` | Consumo por caso de uso y por modelo |
| GET | `/logs/{id}` | Detalle de una llamada |

## Comandos

```bash
make art cmd="llm:list"                  # estado y áreas
make art cmd="llm:test general"          # probar un área
make art cmd="llm:test general --prompt='Resume qué es Laravel'"
make art cmd="llm:prune-logs --dry-run"  # ver qué se limpiaría
```

`llm:prune-logs` se ejecuta solo cada día a las 2:45.

## Añadir un proveedor

Si habla la API de OpenAI, basta con extender la base:

```php
final class MiProveedor extends AbstractHttpProvider
{
    protected function defaultOptions(): array
    {
        return ['response_format' => ['type' => 'json_object']];
    }
}
```

Y añadirlo al `match` de `LlmGateway::buildProvider()`. Si el proveedor tiene
otro formato de mensajes (por ejemplo, la Messages API de Anthropic en directo),
implementa `LlmProvider` desde cero: el contrato es el punto de extensión.

`DeepSeekProvider` sirve de ejemplo de cómo aislar las particularidades de un
proveedor —necesita `thinking: disabled` porque si no consume todo el límite de
tokens razonando y devuelve contenido vacío— sin ensuciar la base común.

## Decisiones de diseño

- **No se fuerzan `temperature` ni `max_tokens`.** Cada modelo trae sus valores
  por defecto y sobrescribirlos a ciegas empeora el resultado. Solo se envían si
  la llamada los especifica.
- **La credencial es global, el modelo es por área.** Una sola API key
  (típicamente OpenRouter) y N modelos elegibles desde el panel.
- **La respuesta nunca es null.** Los consumidores no tienen que null-checkear;
  comprueban `isOk()` y, si quieren afinar, `isUnavailable()`.
- **Los embeddings usan el modelo del área.** Si tu proyecto necesita un modelo
  de embeddings distinto, crea un área específica para ello.

## Referencias

- `app/Services/LLM/LlmGateway.php` → punto de entrada
- `config/llm.php` → credenciales, timeouts, circuit breaker, herramientas
- `docs/features-usage-examples.md` → feature flags
