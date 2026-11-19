<?php

namespace App\Support\Settings;

use App\Exceptions\SettingException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Definición inmutable de un ajuste declarado en `config/settings.php`. Sin estado: se construye
 * desde config cada vez (barato) y es seguro con Octane. Valida y normaliza por tipo
 * (bool/int/string/enum/json) y sabe codificarse para la tabla `settings`.
 */
final class SettingDefinition
{
    public const TYPES = ['bool', 'int', 'string', 'enum', 'json'];

    /** Tamaño máximo de un valor `json` serializado (bytes). */
    public const MAX_JSON_BYTES = 16384;

    /**
     * @param  list<string>  $values
     * @param  list<mixed>  $rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly array $values = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        public readonly array $rules = [],
        public readonly bool $platform = true,
        public readonly bool $tenant = false,
        public readonly ?string $group = null,
        public readonly ?string $description = null,
    ) {}

    public static function find(string $key): ?self
    {
        // Sin notación de puntos de config(): las keys llevan puntos (`general.theme`).
        $config = ((array) config('settings.definitions', []))[$key] ?? null;

        return is_array($config) && in_array($config['type'] ?? null, self::TYPES, true)
            ? self::fromConfig($key, $config)
            : null;
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        $all = [];

        foreach (array_keys((array) config('settings.definitions', [])) as $key) {
            $definition = self::find((string) $key);

            if ($definition !== null) {
                $all[(string) $key] = $definition;
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            type: (string) $config['type'],
            default: $config['default'] ?? null,
            values: array_values(array_map('strval', (array) ($config['values'] ?? []))),
            min: isset($config['min']) ? (int) $config['min'] : null,
            max: isset($config['max']) ? (int) $config['max'] : null,
            rules: array_values((array) ($config['rules'] ?? [])),
            platform: (bool) ($config['platform'] ?? true),
            tenant: (bool) ($config['tenant'] ?? false),
            group: $config['group'] ?? null,
            description: $config['description'] ?? null,
        );
    }

    /**
     * Valida y devuelve el valor con su tipo PHP exacto.
     *
     * @throws SettingException 422 `setting_invalid_value`
     */
    public function normalize(mixed $value): mixed
    {
        $validator = Validator::make(['value' => $value], ['value' => $this->validationRules()]);

        if ($validator->fails()) {
            throw SettingException::invalid($this->key, ['value' => $validator->errors()->get('value')]);
        }

        return match ($this->type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'json' => $this->checkJsonSize($value),
            default => (string) $value,
        };
    }

    /**
     * Reglas Laravel del tipo + las extra de la definición.
     *
     * @return list<mixed>
     */
    private function validationRules(): array
    {
        $rules = ['present'];

        switch ($this->type) {
            case 'bool':
                // Estricto: solo true/false (o 0/1); nada de "yes".
                $rules[] = 'boolean';
                break;
            case 'int':
                $rules[] = 'integer';
                if ($this->min !== null) {
                    $rules[] = 'min:'.$this->min;
                }
                if ($this->max !== null) {
                    $rules[] = 'max:'.$this->max;
                }
                break;
            case 'enum':
                $rules[] = 'string';
                $rules[] = Rule::in($this->values);
                break;
            case 'json':
                $rules[] = 'array';
                break;
            default:
                $rules[] = 'string';
                $rules[] = 'min:'.($this->min ?? 0);
                $rules[] = 'max:'.($this->max ?? 1000);
        }

        return [...$rules, ...$this->rules];
    }

    private function checkJsonSize(mixed $value): mixed
    {
        if (strlen((string) json_encode($value)) > self::MAX_JSON_BYTES) {
            throw SettingException::invalid($this->key, ['value' => ['El JSON supera '.self::MAX_JSON_BYTES.' bytes.']]);
        }

        return $value;
    }

    /**
     * El CRUD genérico recibe el valor como texto (`"true"`, `"5"`, `"{...}"`): lo convierte al tipo
     * de la definición antes de validar. Un valor que no se puede convertir se deja tal cual (falla al validar).
     */
    public function fromStorageInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($this->type) {
            'bool' => match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            'json' => json_decode($value, true) ?? $value,
            default => $value,
        };
    }

    /** Valor por defecto con su tipo. */
    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /** Tipo de la tabla `settings` (`SettingsService::validateType`). */
    public function storageType(): string
    {
        return match ($this->type) {
            'bool' => 'boolean',
            'int' => 'integer',
            'json' => 'json',
            default => 'string',
        };
    }

    /** Valor normalizado -> texto de la columna `settings.value`. */
    public function encode(mixed $value): string
    {
        return match ($this->type) {
            'bool' => $value ? 'true' : 'false',
            'json' => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /** Texto de `settings.value` -> valor PHP (o el default si no se puede leer). */
    public function decode(?string $raw): mixed
    {
        if ($raw === null) {
            return $this->defaultValue();
        }

        return match ($this->type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => is_numeric($raw) ? (int) $raw : $this->defaultValue(),
            'json' => json_decode($raw, true) ?? $this->defaultValue(),
            default => $raw,
        };
    }
}
