<?php

namespace App\Support\Spreadsheet;

use BackedEnum;
use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Stringable;

/**
 * Escritor en streaming (una fila a la vez, nunca todo en memoria) de CSV o XLSX con openspout.
 * Neutraliza la inyección de fórmulas: una celda de texto que empieza por `= + - @` (o tab/CR) se
 * escribe con un apóstrofo delante, para que Excel/Calc no la ejecute (OWASP «CSV injection»).
 */
final class SpreadsheetWriter
{
    private CsvWriter|XlsxWriter $writer;

    public function __construct(string $path, string $format)
    {
        $this->writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $this->writer->openToFile($path);
    }

    /**
     * @param  list<mixed>  $values
     */
    public function write(array $values): void
    {
        $this->writer->addRow(Row::fromValues(array_map(self::cell(...), $values)));
    }

    public function close(): void
    {
        $this->writer->close();
    }

    public static function cell(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
