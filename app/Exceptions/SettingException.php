<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;

/**
 * Errores de dominio de los ajustes declarados (docs/settings-and-flags.md). Cada uno lleva un
 * `code` estable: `setting_unknown` (404), `setting_not_editable` (403), `setting_invalid_value` (422).
 */
class SettingException extends ApiException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    private function __construct(string $message, int $status, private readonly string $stableCode, array $errors = [])
    {
        parent::__construct($message, $status, $errors, null, null, ['setting_code' => $stableCode]);
    }

    public function errorCode(): ErrorCode|string
    {
        return $this->stableCode;
    }

    public static function unknown(string $key): self
    {
        return new self("El ajuste '{$key}' no existe", 404, 'setting_unknown');
    }

    public static function notEditable(string $key, string $level): self
    {
        return new self("El ajuste '{$key}' no es editable a nivel {$level}", 403, 'setting_not_editable');
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function invalid(string $key, array $errors): self
    {
        return new self("Valor no válido para el ajuste '{$key}'", 422, 'setting_invalid_value', $errors);
    }
}
