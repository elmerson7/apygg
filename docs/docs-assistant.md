# Asistente de documentación

Indexa los `.md` del proyecto y permite buscarlos o preguntarles en lenguaje
natural. La documentación deja de ser algo que nadie lee y pasa a responder
preguntas.

Es un RAG deliberadamente sencillo: búsqueda con el **full-text nativo de
PostgreSQL** y los fragmentos relevantes como contexto para el modelo. Sin
pgvector, sin embeddings, sin servicios externos.

## Por qué sin embeddings

Para la documentación de un proyecto —decenas de documentos, no millones— la
búsqueda léxica da resultados equiparables a la semántica, y a un coste
radicalmente menor: cero llamadas de embedding al indexar, cero al consultar,
ninguna extensión que instalar y ningún índice que reconstruir.

La búsqueda semántica gana cuando el usuario pregunta con palabras que no
aparecen en el texto. Si llegas a ese punto, la sección final explica cómo
añadirla.

## Puesta en marcha

```bash
make art cmd="migrate"
make art cmd="docs:index"
```

El comando solo reindexa lo que cambió (compara el hash del contenido), así que
puede ir en el despliegue sin coste apreciable:

```bash
# scripts/post-deploy.sh
php artisan docs:index
```

## Cómo se indexa

Los documentos se parten **por encabezados**, no por número de caracteres. Un
corte a mitad de sección produce fragmentos sin contexto ("...y por eso hay que
configurarlo así") que el modelo no sabe usar.

| Ajuste | Qué hace |
|---|---|
| `heading_level` | Nivel por el que se parte (2 = `##`) |
| `max_chars` | Corta las secciones largas, por párrafos |
| `min_chars` | Descarta lo que no aporta (un encabezado con una línea) |

El frontmatter YAML se descarta: son metadatos, no contenido consultable.

Los documentos que desaparecen del repositorio se eliminan del índice. Si no, el
asistente seguiría citando documentación que ya no existe.

### Scopes

`config/docs-assistant.php` → `sources` asocia rutas a un scope, lo que permite
separar audiencias:

```php
'sources' => [
    'general' => ['docs'],
    'usuario' => ['docs/user-guide'],
],
```

Cada consulta se hace dentro de un scope.

## Uso

```http
GET  /docs?scope=general             # documentos indexados
GET  /docs/search?q=invalidar+caché  # búsqueda directa, sin IA
POST /docs/ask                       # pregunta en lenguaje natural
GET  /docs/conversations             # hilos del usuario
```

```json
POST /docs/ask
{
  "question": "¿Cómo invalido la caché de un listado público?",
  "scope": "general",
  "conversation_id": 12
}
```

Respuesta:

```json
{
  "answer": "Según la documentación...",
  "sources": [
    { "doc_id": "docs/cache-invalidation.md", "heading": "Invalidación", "reference": "docs/cache-invalidation.md › Invalidación" }
  ],
  "available": true,
  "conversation_id": 12
}
```

Con `conversation_id` se continúa un hilo: el historial se incluye en el prompt,
de modo que el usuario puede repreguntar ("¿y cómo lo configuro?") sin repetir
el contexto.

Los endpoints requieren autenticación: la documentación técnica no suele ser
pública y cada pregunta consume cuota de IA.

## El modelo no improvisa

El prompt es explícito: responder **solo** con lo que hay en los fragmentos y
decir claramente cuándo la documentación no cubre algo.

Un asistente de documentación que completa con conocimiento general es peor que
no tener asistente: produce respuestas plausibles y equivocadas sobre tu propio
sistema, y quien pregunta no tiene forma de distinguirlas.

Por eso toda respuesta viene con sus `sources`: son verificables.

## Sin LLM configurado

El buscador sigue funcionando. `/docs/search` no usa IA en absoluto, y `/docs/ask`
devuelve los fragmentos relevantes con `available: false` en lugar de fallar.

## Búsqueda: cómo funciona

La columna `search_vector` es **generada**: PostgreSQL la mantiene sola en cada
INSERT y UPDATE, así que no hay forma de que el índice quede desincronizado del
contenido. Pesa por relevancia — encabezado (A) sobre título (B) sobre contenido
(C) — porque una pregunta suele nombrar la sección que busca.

Se consulta con `websearch_to_tsquery`, que entiende comillas y operadores como
un buscador web y, a diferencia de `to_tsquery`, no revienta con entrada libre.

Si el full-text no encuentra nada —términos muy específicos, nombres de clases,
palabras que el diccionario no reconoce— se intenta una búsqueda literal antes
de rendirse.

El idioma del diccionario se configura en `text_search_config` (`spanish` por
defecto). Cambiarlo exige regenerar la columna: rollback y migrate de la
migración de docs.

## Si necesitas búsqueda semántica

1. Instala pgvector en PostgreSQL y `CREATE EXTENSION vector`.
2. Añade `ALTER TABLE doc_chunks ADD COLUMN embedding vector(1536)` y un índice
   `ivfflat`.
3. En `DocsIndexer`, genera el embedding de cada fragmento con
   `LlmGateway::embed()`.
4. En `DocsService::search()`, ordena por distancia coseno y deja el full-text
   como respaldo.

El gateway ya expone `embed()`, así que el punto de enganche está puesto.

## Comandos

```bash
make art cmd="docs:index"           # incremental
make art cmd="docs:index --force"   # reindexar todo
```

## Referencias

- `app/Services/Docs/DocsIndexer.php` → fragmentación e indexado
- `app/Services/Docs/DocsService.php` → búsqueda y consulta
- `config/docs-assistant.php` → fuentes, fragmentación, idioma
- `docs/llm.md` → el área `docs` del gateway
