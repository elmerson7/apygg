<?php

namespace App\Contracts;

interface MetricsServiceInterface
{
    /**
     * Anota una petición HTTP atendida (contador por ruta/método/estado e histograma de latencia).
     * No lanza: una caída de Redis nunca debe romper una respuesta.
     *
     * @param  string  $route  plantilla de la ruta (`users/{user}`), nunca la URL real
     */
    public function recordRequest(string $method, string $route, int $status, float $seconds): void;

    /** Documento completo en formato de texto de Prometheus (peticiones, colas, jobs fallidos y BD). */
    public function render(): string;
}
