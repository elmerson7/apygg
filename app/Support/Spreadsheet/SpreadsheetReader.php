<?php

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Lector en streaming de CSV o XLSX (primera hoja). Entrega `número de fila del archivo => celdas` sin
 * cargar el archivo entero. El separador del CSV (`,` `;` tab) se detecta por la primera línea.
 */
final class SpreadsheetReader
{
    /**
     * @return Generator<int, list<string|null>>
     */
    public static function rows(string $path, string $format): Generator
    {
        if ($format === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = self::detectDelimiter($path);
            $reader = new CsvReader($options);
        }

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $index => $row) {
                    yield (int) $index => array_map(self::normalize(...), $row->toArray());
                }

                break; // solo la primera hoja
            }
        } finally {
            $reader->close();
        }
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle ? (string) fgets($handle, 8192) : '';

        if ($handle) {
            fclose($handle);
        }

        $best = ',';
        $max = 0;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($line, $candidate);

            if ($count > $max) {
                $max = $count;
                $best = $candidate;
            }
        }

        return $best;
    }
}
