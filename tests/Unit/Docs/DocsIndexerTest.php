<?php

use App\Models\Docs\DocChunk;
use App\Services\Docs\DocsIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->indexer = app(DocsIndexer::class);
    config()->set('docs-assistant.chunking', ['max_chars' => 200, 'min_chars' => 20, 'heading_level' => 2]);
});

$markdown = <<<'MD'
---
title: no debería aparecer
---
# Guía de ejemplo

Introducción del documento que es suficientemente larga para no descartarse.

## Primera sección

Contenido de la primera sección, con material bastante para superar el mínimo.

## Segunda sección

Contenido de la segunda sección, también con longitud suficiente.
MD;

test('parte el documento por encabezados de nivel 2', function () use ($markdown) {
    $chunks = $this->indexer->chunk($markdown, 'docs/ejemplo.md', 'general', 'Guía', 'hash');

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]['heading'])->toBeNull()          // introducción, antes del primer ##
        ->and($chunks[1]['heading'])->toBe('Primera sección')
        ->and($chunks[2]['heading'])->toBe('Segunda sección');
});

test('descarta el frontmatter', function () use ($markdown) {
    $chunks = $this->indexer->chunk($markdown, 'docs/ejemplo.md', 'general', 'Guía', 'hash');

    foreach ($chunks as $chunk) {
        expect($chunk['content'])->not->toContain('no debería aparecer');
    }
});

test('descarta los fragmentos por debajo del mínimo', function () {
    config()->set('docs-assistant.chunking.min_chars', 500);

    expect($this->indexer->chunk("# T\n\n## A\n\ncorto", 'docs/x.md', 'general', 'T', 'h'))->toBeEmpty();
});

test('corta las secciones largas por párrafos', function () {
    $long = "## Sección\n\n".str_repeat("Párrafo de relleno bastante largo.\n\n", 20);

    $chunks = $this->indexer->chunk($long, 'docs/x.md', 'general', 'T', 'h');

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        // El corte es por párrafos, así que puede exceder ligeramente el máximo
        expect(mb_strlen($chunk['content']))->toBeLessThan(400);
    }
});

test('numera los fragmentos en orden', function () use ($markdown) {
    $chunks = $this->indexer->chunk($markdown, 'docs/ejemplo.md', 'general', 'Guía', 'hash');

    expect(array_column($chunks, 'position'))->toBe([0, 1, 2]);
});

test('indexa un fichero real y lo omite si no cambió', function () {
    $path = base_path('storage/framework/testing-doc.md');
    file_put_contents($path, "# Título\n\n## Sección\n\nContenido con longitud suficiente para indexarse.\n");

    config()->set('docs-assistant.sources', ['general' => ['storage/framework/testing-doc.md']]);

    $first = $this->indexer->reindex();
    expect($first['files'])->toBe(1)
        ->and($first['chunks'])->toBeGreaterThan(0)
        ->and(DocChunk::count())->toBe($first['chunks']);

    $second = $this->indexer->reindex();
    expect($second['skipped'])->toBe(1)
        ->and($second['chunks'])->toBe(0);

    unlink($path);
});

test('elimina del índice los documentos que ya no existen', function () {
    DocChunk::create([
        'doc_id' => 'docs/borrado.md',
        'scope' => 'general',
        'content' => 'contenido antiguo',
        'position' => 0,
    ]);

    config()->set('docs-assistant.sources', ['general' => ['docs/no-existe']]);

    $stats = $this->indexer->reindex();

    expect($stats['removed'])->toBe(1)
        ->and(DocChunk::count())->toBe(0);
});
