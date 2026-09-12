<?php

namespace App\Services\Docs;

use App\Models\Docs\DocChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * DocsIndexer
 *
 * Convierte los ficheros Markdown del proyecto en fragmentos consultables.
 *
 * Se parte por encabezados en lugar de por número de caracteres: un corte a
 * mitad de sección produce fragmentos sin contexto ("...y por eso hay que
 * configurarlo así") que el modelo no sabe usar.
 */
class DocsIndexer
{
    /**
     * Reindexar todas las fuentes configuradas.
     *
     * @return array<string, int>
     */
    public function reindex(bool $force = false): array
    {
        $stats = ['files' => 0, 'chunks' => 0, 'skipped' => 0, 'removed' => 0];
        $seenDocIds = [];

        foreach ((array) config('docs-assistant.sources', []) as $scope => $paths) {
            foreach ((array) $paths as $path) {
                foreach ($this->findMarkdownFiles($path) as $file) {
                    $docId = $this->docId($file);
                    $seenDocIds[] = $docId;

                    $result = $this->indexFile($file, (string) $scope, $force);

                    $stats['files']++;
                    $stats['chunks'] += $result['chunks'];
                    $stats['skipped'] += $result['skipped'] ? 1 : 0;
                }
            }
        }

        // Los documentos borrados del repo deben desaparecer del índice: si no,
        // el asistente sigue citando documentación que ya no existe.
        $stats['removed'] = DocChunk::query()->whereNotIn('doc_id', $seenDocIds ?: [''])->delete();

        return $stats;
    }

    /**
     * Indexar un fichero. Si su contenido no cambió, no se toca.
     *
     * @return array{chunks: int, skipped: bool}
     */
    public function indexFile(string $absolutePath, string $scope, bool $force = false): array
    {
        $content = (string) file_get_contents($absolutePath);
        $docId = $this->docId($absolutePath);
        $hash = hash('sha256', $content);

        if (! $force && $this->isUpToDate($docId, $hash)) {
            return ['chunks' => 0, 'skipped' => true];
        }

        $title = $this->extractTitle($content) ?: Str::headline(basename($absolutePath, '.md'));
        $chunks = $this->chunk($content, $docId, $scope, $title, $hash);

        DB::transaction(function () use ($docId, $chunks) {
            DocChunk::where('doc_id', $docId)->delete();

            foreach (array_chunk($chunks, 100) as $batch) {
                DocChunk::insert($batch);
            }
        });

        return ['chunks' => count($chunks), 'skipped' => false];
    }

    /**
     * Partir el documento por encabezados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chunk(string $content, string $docId, string $scope, string $title, string $hash): array
    {
        $level = (int) config('docs-assistant.chunking.heading_level', 2);
        $maxChars = (int) config('docs-assistant.chunking.max_chars', 4000);
        $minChars = (int) config('docs-assistant.chunking.min_chars', 80);

        $body = $this->stripFrontmatter($content);
        $marker = str_repeat('#', $level);

        // Se separa en el salto de línea previo al encabezado, conservándolo.
        $sections = preg_split('/^(?='.preg_quote($marker, '/').' )/m', $body) ?: [];

        $chunks = [];
        $position = 0;
        $now = now();

        foreach ($sections as $section) {
            $section = trim($section);

            if ($section === '') {
                continue;
            }

            $heading = $this->extractHeading($section, $level);

            foreach ($this->splitLongSection($section, $maxChars) as $part) {
                if (mb_strlen(trim($part)) < $minChars) {
                    continue;
                }

                $chunks[] = [
                    'doc_id' => $docId,
                    'scope' => $scope,
                    'title' => $title,
                    'heading' => $heading,
                    'content' => trim($part),
                    'content_hash' => $hash,
                    'position' => $position++,
                    'metadata' => json_encode(['chars' => mb_strlen($part)]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $chunks;
    }

    /**
     * Cortar una sección demasiado larga por párrafos, no a mitad de frase.
     *
     * @return array<int, string>
     */
    protected function splitLongSection(string $section, int $maxChars): array
    {
        if (mb_strlen($section) <= $maxChars) {
            return [$section];
        }

        $parts = [];
        $current = '';

        foreach (explode("\n\n", $section) as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) > $maxChars) {
                $parts[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : "\n\n").$paragraph;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    protected function isUpToDate(string $docId, string $hash): bool
    {
        return DocChunk::where('doc_id', $docId)->where('content_hash', $hash)->exists();
    }

    /**
     * @return array<int, string>
     */
    protected function findMarkdownFiles(string $path): array
    {
        $absolute = base_path($path);

        if (is_file($absolute)) {
            return [$absolute];
        }

        if (! is_dir($absolute)) {
            return [];
        }

        $files = [];

        foreach ((new Finder)->files()->in($absolute)->name('*.md')->sortByName() as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    /**
     * Identificador estable del documento: su ruta relativa al proyecto.
     */
    protected function docId(string $absolutePath): string
    {
        return ltrim(str_replace(base_path(), '', $absolutePath), '/');
    }

    protected function extractTitle(string $content): ?string
    {
        return preg_match('/^#\s+(.+)$/m', $this->stripFrontmatter($content), $matches) === 1
            ? trim($matches[1])
            : null;
    }

    protected function extractHeading(string $section, int $level): ?string
    {
        $pattern = '/^'.str_repeat('#', $level).'\s+(.+)$/m';

        return preg_match($pattern, $section, $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * Quitar el frontmatter YAML: son metadatos, no contenido consultable.
     */
    protected function stripFrontmatter(string $content): string
    {
        return (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
    }
}
