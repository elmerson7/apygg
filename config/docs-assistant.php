<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asistente de Documentación
    |--------------------------------------------------------------------------
    |
    | Indexa los .md del proyecto y permite preguntarles en lenguaje natural.
    | Ver docs/docs-assistant.md.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Rutas a Indexar
    |--------------------------------------------------------------------------
    |
    | Directorios o ficheros, relativos a la raíz del proyecto. Cada entrada
    | define el "scope" con el que se indexan sus documentos, lo que permite
    | separar la documentación interna de la que ve el usuario final.
    |
    */

    'sources' => [
        'general' => ['docs'],
        // 'usuario' => ['docs/user-guide'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Búsqueda Full-Text
    |--------------------------------------------------------------------------
    |
    | Configuración de idioma de PostgreSQL para el tsvector: define cómo se
    | reducen las palabras a su raíz. 'spanish', 'english', 'simple'…
    |
    | Cambiarla exige regenerar la columna: rollback + migrate de la migración
    | de docs.
    |
    */

    'text_search_config' => env('DOCS_TEXT_SEARCH_CONFIG', 'spanish'),

    /*
    |--------------------------------------------------------------------------
    | Fragmentación
    |--------------------------------------------------------------------------
    |
    | Los documentos se parten por encabezados. `max_chars` corta las secciones
    | demasiado largas; `min_chars` descarta las que no aportan (un encabezado
    | con una línea debajo).
    |
    */

    'chunking' => [
        'max_chars' => env('DOCS_CHUNK_MAX_CHARS', 4000),
        'min_chars' => env('DOCS_CHUNK_MIN_CHARS', 80),
        'heading_level' => env('DOCS_CHUNK_HEADING_LEVEL', 2), // parte por ##
    ],

    /*
    |--------------------------------------------------------------------------
    | Recuperación
    |--------------------------------------------------------------------------
    |
    | Fragmentos que se recuperan por pregunta. Más contexto no siempre es
    | mejor: diluye la pregunta y encarece cada llamada.
    |
    */

    'retrieval' => [
        'limit' => env('DOCS_RETRIEVAL_LIMIT', 5),
    ],

];
