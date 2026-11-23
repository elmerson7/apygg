<?php

namespace App\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Lectura tipada de argumentos y opciones de un comando.
 *
 * `Command::option()` y `argument()` devuelven `array|string|bool|null`; estos helpers
 * devuelven un tipo concreto sin castear a ciegas (un array no se convierte en "Array").
 *
 * @phpstan-require-extends Command
 */
trait ReadsTypedInput
{
    /** Opción como string; null si no se pasó (o es un array/booleano sin valor). */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }

    /** Argumento como string; null si falta o no es un valor escalar. */
    protected function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : null);
    }

    /** Opción de tipo bandera (`--force`) o con valor booleano. */
    protected function boolOption(string $name): bool
    {
        return filter_var($this->option($name), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Opción repetible (`--tags=a --tags=b`) como lista de strings.
     *
     * @return list<string>
     */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        return is_array($value)
            ? array_values(array_filter($value, is_string(...)))
            : [];
    }
}
