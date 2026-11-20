<?php

namespace App\Support\Database;

/**
 * Análisis estático (por texto) de una migración: devuelve los patrones peligrosos para
 * desplegar sin parada. No ejecuta nada ni toca la BD. Reglas en docs/migrations.md §9.
 *
 * Solo mira `up()`. Excepción por línea o archivo: `// migration-lint: ignore regla[,regla] -- motivo`.
 */
final class MigrationLinter
{
    public const RULES = [
        'change-type' => '->change() reescribe la tabla o cambia el tipo: usa columna nueva + copia + borrado',
        'rename-column' => 'renameColumn rompe al código viejo durante el despliegue: expandir/contraer',
        'drop-column' => 'dropColumn/dropIfExists en up(): el código anterior aún puede usarla; sepárala en una entrega posterior',
        'index-not-concurrent' => 'índice sobre tabla grande sin CREATE INDEX CONCURRENTLY ($withinTransaction = false)',
        'not-null-no-default' => 'columna NOT NULL sin default sobre tabla existente (o SET NOT NULL directo): usa nullable/default o CHECK NOT VALID + VALIDATE',
    ];

    /** Métodos de Blueprint que NO crean una columna de datos. */
    private const NON_COLUMN = [
        'index', 'unique', 'primary', 'foreign', 'fullText', 'spatialIndex', 'rawIndex', 'dropColumn', 'dropIndex',
        'dropUnique', 'dropPrimary', 'dropForeign', 'dropTimestamps', 'dropSoftDeletes', 'dropRememberToken',
        'renameColumn', 'renameIndex', 'drop', 'dropIfExists', 'id', 'timestamps', 'timestampsTz', 'softDeletes',
        'softDeletesTz', 'rememberToken', 'nullableTimestamps', 'nullableMorphs', 'nullableUuidMorphs', 'comment',
        'engine', 'charset', 'collation', 'temporary', 'constrained', 'cascadeOnDelete', 'nullOnDelete',
    ];

    /**
     * @param  array<int, string>  $largeTables
     * @return list<array{rule: string, line: int, message: string}>
     */
    public function lint(string $source, array $largeTables = []): array
    {
        $ignored = $this->ignoredRules($source);
        if (in_array('*', $ignored, true)) {
            return [];
        }

        $up = $this->upBody($source);
        $offset = $up['offset'];
        $body = $up['body'];
        $found = [];

        $add = function (string $rule, int $pos, ?string $extra = null) use (&$found, $source, $offset, $ignored): void {
            if (in_array($rule, $ignored, true)) {
                return;
            }
            $line = substr_count(substr($source, 0, $offset + $pos), "\n") + 1;
            if ($this->lineIgnores($source, $line, $rule)) {
                return;
            }
            $found[] = ['rule' => $rule, 'line' => $line, 'message' => self::RULES[$rule].($extra ? " ($extra)" : '')];
        };

        foreach ($this->matches('/->change\(\s*\)/', $body) as $pos) {
            $add('change-type', $pos);
        }
        foreach ($this->matches('/->renameColumn\(|RENAME\s+COLUMN/i', $body) as $pos) {
            $add('rename-column', $pos);
        }
        foreach ($this->matches('/->dropColumn\(|->dropColumns\(|->dropSoftDeletes\(|->dropTimestamps\(|->dropRememberToken\(|Schema::drop(?:IfExists)?\(|DROP\s+(?:COLUMN|TABLE)\b/i', $body) as $pos) {
            $add('drop-column', $pos);
        }

        foreach ($this->blocks($body) as $block) {
            $isNew = $block['kind'] === 'create';
            foreach ($this->matches('/\$table->(index|unique|fullText|spatialIndex|rawIndex)\(/', $block['text']) as $rel) {
                if (! $isNew && in_array($block['table'], $largeTables, true)) {
                    $add('index-not-concurrent', $block['start'] + $rel, $block['table']);
                }
            }
            if (! $isNew) {
                foreach ($this->newColumns($block['text']) as [$rel, $method]) {
                    $add('not-null-no-default', $block['start'] + $rel, $block['table'].'.'.$method);
                }
            }
        }

        // SQL crudo
        foreach ($this->matchesWithGroup('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY)(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+ON\s+"?(\w+)"?/i', $body) as [$pos, $table]) {
            if (in_array($table, $largeTables, true)) {
                $add('index-not-concurrent', $pos, $table);
            }
        }
        foreach ($this->matches('/ALTER\s+COLUMN\s+\S+\s+SET\s+NOT\s+NULL/i', $body) as $pos) {
            $add('not-null-no-default', $pos, 'SET NOT NULL');
        }
        foreach ($this->matches('/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?[^;\'"]*?NOT\s+NULL(?![^;\'"]*DEFAULT)/i', $body) as $pos) {
            $add('not-null-no-default', $pos, 'ADD COLUMN NOT NULL');
        }

        usort($found, fn ($a, $b) => $a['line'] <=> $b['line']);

        return $found;
    }

    /** @return array{offset: int, body: string} */
    private function upBody(string $source): array
    {
        if (! preg_match('/function\s+up\s*\([^)]*\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return ['offset' => 0, 'body' => ''];
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                break;
            }
        }

        return ['offset' => $start, 'body' => substr($source, $start, $i - $start)];
    }

    /** @return list<array{kind: string, table: string, text: string, start: int}> */
    private function blocks(string $body): array
    {
        $blocks = [];
        if (preg_match_all('/Schema::(create|table)\(\s*[\'"](\w+)[\'"]/', $body, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            $n = count($m);
            foreach ($m as $i => $hit) {
                $start = $hit[0][1];
                $end = $i + 1 < $n ? $m[$i + 1][0][1] : strlen($body);
                $blocks[] = ['kind' => $hit[1][0], 'table' => $hit[2][0], 'text' => substr($body, $start, $end - $start), 'start' => $start];
            }
        }

        return $blocks;
    }

    /** @return list<array{int, string}> columnas añadidas a tabla existente sin nullable ni default */
    private function newColumns(string $text): array
    {
        $out = [];
        foreach ($this->matchesWithGroup('/\$table->(\w+)\(/', $text) as [$pos, $method]) {
            if (in_array($method, self::NON_COLUMN, true) || str_starts_with($method, 'drop') || str_starts_with($method, 'rename')) {
                continue;
            }
            $end = strpos($text, ';', $pos);
            $stmt = substr($text, $pos, ($end === false ? strlen($text) : $end) - $pos);
            if (preg_match('/->(nullable|default|useCurrent|storedAs|virtualAs|generatedAs|change)\(/', $stmt)) {
                continue;
            }
            $out[] = [$pos, $method];
        }

        return $out;
    }

    /** @return list<int> posiciones de cada coincidencia */
    private function matches(string $regex, string $text): array
    {
        return array_map(fn (array $hit): int => $hit[0], $this->matchesWithGroup($regex, $text));
    }

    /** @return list<array{int, string}> posición y primer grupo de cada coincidencia */
    private function matchesWithGroup(string $regex, string $text): array
    {
        if (! preg_match_all($regex, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        return array_map(fn (array $hit): array => [(int) $hit[0][1], (string) ($hit[1][0] ?? '')], $m);
    }

    /** @return list<string> reglas ignoradas en todo el archivo (marca antes de `return new class`) */
    private function ignoredRules(string $source): array
    {
        $head = preg_split('/return\s+new\s+class/', $source)[0];
        if (! preg_match_all('/migration-lint:\s*ignore\s+([a-z*\-]+(?:\s*,\s*[a-z*\-]+)*)/', $head, $m)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', implode(',', $m[1])))));
    }

    private function lineIgnores(string $source, int $line, string $rule): bool
    {
        $lines = explode("\n", $source);
        foreach ([$line - 1, $line - 2] as $idx) {
            if (isset($lines[$idx]) && preg_match('/migration-lint:\s*ignore\s+([a-z*\-]+(?:\s*,\s*[a-z*\-]+)*)/', $lines[$idx], $m)) {
                $rules = array_map('trim', explode(',', $m[1]));
                if (in_array($rule, $rules, true) || in_array('*', $rules, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
