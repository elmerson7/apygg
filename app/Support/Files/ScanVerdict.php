<?php

namespace App\Support\Files;

/**
 * Resultado de un escáner antivirus. `error` con `retryable` = fallo pasajero (servicio caído, límite de peticiones):
 * el job lo reintenta. Sin `retryable` (archivo demasiado grande, configuración inválida) no sirve repetir.
 */
final readonly class ScanVerdict
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const ERROR = 'error';

    private function __construct(
        public string $outcome,
        public ?string $detail = null,
        public bool $retryable = false,
    ) {}

    public static function clean(?string $detail = null): self
    {
        return new self(self::CLEAN, $detail);
    }

    public static function infected(string $signature): self
    {
        return new self(self::INFECTED, $signature);
    }

    public static function error(string $reason, bool $retryable = true): self
    {
        return new self(self::ERROR, $reason, $retryable);
    }

    public function isClean(): bool
    {
        return $this->outcome === self::CLEAN;
    }

    public function isInfected(): bool
    {
        return $this->outcome === self::INFECTED;
    }

    public function isError(): bool
    {
        return $this->outcome === self::ERROR;
    }
}
