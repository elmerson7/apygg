<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga CSV en streaming de la auditoría. Las celdas que empiezan por `= + - @` (o tab/CR) se
 * prefijan con `'` para que una hoja de cálculo no las ejecute como fórmula (CSV injection).
 */
trait StreamsAuditCsv
{
    /**
     * @param  iterable<list<string>>  $rows
     */
    protected function csvResponse(iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safeCell(...), $row), ',', '"', '\\');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private static function safeCell(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
