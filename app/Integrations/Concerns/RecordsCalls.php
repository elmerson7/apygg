<?php

namespace App\Integrations\Concerns;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\PermanentIntegrationError;
use InvalidArgumentException;

/**
 * Para las implementaciones FALSAS de una integración: registra las llamadas (para asserts) e
 * inyecta fallos por tipo. Estado solo de instancia: el binding es `scoped`, nunca estático.
 *
 *     $fake->failNext(TransientIntegrationError::class, times: 2);
 *     // las dos próximas llamadas lanzan; la tercera funciona
 *
 * Sin helpers de PHPUnit (la clase vive en `app/`, no en dev): los tests hacen `expect($fake->calls('send'))`.
 * La clase que lo usa llama a `$this->record('send', $payload)` al principio de cada operación
 * pública; `record()` lanza el fallo pendiente (después de registrar la llamada).
 */
trait RecordsCalls
{
    /** @var list<array{operation: string, payload: mixed}> */
    private array $recordedCalls = [];

    /** @var list<class-string<IntegrationException>> */
    private array $pendingFailures = [];

    /**
     * @param  class-string<IntegrationException>  $type
     */
    public function failNext(string $type = PermanentIntegrationError::class, int $times = 1): static
    {
        if (! is_subclass_of($type, IntegrationException::class)) {
            throw new InvalidArgumentException("{$type} no es un error de integración");
        }

        for ($i = 0; $i < $times; $i++) {
            $this->pendingFailures[] = $type;
        }

        return $this;
    }

    /**
     * @return list<array{operation: string, payload: mixed}>
     */
    public function calls(?string $operation = null): array
    {
        return array_values(array_filter(
            $this->recordedCalls,
            fn (array $call) => $operation === null || $call['operation'] === $operation,
        ));
    }

    public function resetFake(): void
    {
        $this->recordedCalls = [];
        $this->pendingFailures = [];
    }

    protected function record(string $operation, mixed $payload): void
    {
        $this->recordedCalls[] = ['operation' => $operation, 'payload' => $payload];

        $failure = array_shift($this->pendingFailures);

        if ($failure !== null) {
            throw new $failure('Fallo inyectado por la integración falsa', $this->serviceName());
        }
    }

    /** Nombre del servicio para los errores (`sms`...). */
    abstract protected function serviceName(): string;
}
