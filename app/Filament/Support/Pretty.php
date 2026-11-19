<?php

namespace App\Filament\Support;

use App\Helpers\Redactor;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\FontFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * Presentación de datos de logs en el panel.
 *
 * Los logs ya se guardan redactados (ActivityLogger, ApiLogger, SecurityLogger), pero filas
 * antiguas o escritas por otra vía podrían traer secretos: se vuelve a redactar al mostrar
 * con las mismas claves (`config/logging.php` → redact_keys / redact_key_fragments).
 */
final class Pretty
{
    public static function json(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            $value = Redactor::redact($value);
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Entrada de infolist con un campo JSON redactado. Se calcula el estado completo (no
     * `formatStateUsing`): Filament trata un array como lista y formatearía cada valor suelto,
     * sin ver la clave que decide si es un secreto.
     */
    public static function entry(string $attribute, string $label): TextEntry
    {
        return TextEntry::make($attribute)
            ->label($label)
            ->state(fn (Model $record): string => self::json($record->getAttribute($attribute)))
            ->fontFamily(FontFamily::Mono)
            ->extraAttributes(['class' => 'whitespace-pre-wrap break-all'])
            ->columnSpanFull();
    }
}
