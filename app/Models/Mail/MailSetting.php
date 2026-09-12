<?php

namespace App\Models\Mail;

use App\Models\Model;

/**
 * MailSetting Model
 *
 * Ajustes globales del sistema de correo, editables en caliente:
 * pausa total de envíos, límite diario y límite por minuto.
 *
 * La pausa global es el botón de emergencia: detiene TODOS los envíos sin
 * necesidad de desplegar ni de tocar variables de entorno.
 */
class MailSetting extends Model
{
    protected $table = 'mail_settings';

    protected $fillable = [
        'key',
        'value',
    ];

    /** Pausa total de envíos (boolean) */
    public const KEY_GLOBAL_PAUSE = 'global_pause';

    /** Máximo de correos por día (int, 0 = sin límite) */
    public const KEY_DAILY_LIMIT = 'daily_limit';

    /** Máximo de correos por minuto (int, 0 = sin límite) */
    public const KEY_RATE_LIMIT = 'rate_limit';

    /**
     * Claves que se interpretan como booleano.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_KEYS = [
        self::KEY_GLOBAL_PAUSE,
    ];

    /**
     * Claves que se interpretan como entero.
     *
     * @var array<int, string>
     */
    private const INTEGER_KEYS = [
        self::KEY_DAILY_LIMIT,
        self::KEY_RATE_LIMIT,
    ];

    /**
     * Leer un ajuste con el tipo correcto.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = self::query()->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
        }

        if (in_array($key, self::INTEGER_KEYS, true)) {
            return (int) $setting->value;
        }

        return $setting->value;
    }

    /**
     * Guardar un ajuste.
     */
    public static function setValue(string $key, mixed $value): self
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return self::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /**
     * ¿Están pausados todos los envíos?
     */
    public static function isGloballyPaused(): bool
    {
        return (bool) self::getValue(self::KEY_GLOBAL_PAUSE, false);
    }
}
