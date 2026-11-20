<?php

namespace App\Support\Modules;

use RuntimeException;

/**
 * Quita de un archivo compartido las líneas que pertenecen a un módulo (docs/modules.md §Marcadores).
 *
 * - Línea: `código; // module:nombre` (o `# module:nombre`, `<!-- module:nombre -->`, `{{-- module:nombre --}}`)
 *   al final de la línea. Se elimina la línea entera.
 * - Bloque: una línea con `module:nombre:start` y otra con `module:nombre:end` (con cualquier comentario);
 *   se eliminan ambas y todo lo que hay entre medias.
 *
 * Sin estado: trabaja sobre texto, no toca disco.
 */
final class MarkerEditor
{
    public function hasMarkers(string $content, string $module): bool
    {
        return str_contains($content, 'module:'.$module);
    }

    /**
     * @return array{content: string, removed: int}
     */
    public function strip(string $content, string $module, string $label = 'archivo'): array
    {
        if (! $this->hasMarkers($content, $module)) {
            return ['content' => $content, 'removed' => 0];
        }

        $name = preg_quote($module, '~');
        $start = '~(?://|#|<!--|\{\{--|/\*|\*)\s*module:'.$name.':start\b~';
        $end = '~(?://|#|<!--|\{\{--|/\*|\*)\s*module:'.$name.':end\b~';
        $line = '~(?://|#|<!--|\{\{--)\s*module:'.$name.'\s*(?:-->|--\}\})?\s*$~';

        $kept = [];
        $removed = 0;
        $open = null;

        foreach (explode("\n", $content) as $number => $text) {
            if ($open !== null) {
                $removed++;

                if (preg_match($end, $text) === 1) {
                    $open = null;
                } elseif (preg_match($start, $text) === 1) {
                    throw new RuntimeException("{$label}:".($number + 1).": bloque module:{$module}:start anidado (el de la línea {$open} no se cerró)");
                }

                continue;
            }

            if (preg_match($start, $text) === 1) {
                $open = $number + 1;
                $removed++;

                continue;
            }

            if (preg_match($end, $text) === 1) {
                throw new RuntimeException("{$label}:".($number + 1).": module:{$module}:end sin su :start");
            }

            if (preg_match($line, $text) === 1) {
                $removed++;

                continue;
            }

            $kept[] = $text;
        }

        if ($open !== null) {
            throw new RuntimeException("{$label}:{$open}: bloque module:{$module}:start sin cerrar (falta :end)");
        }

        return ['content' => implode("\n", $kept), 'removed' => $removed];
    }
}
