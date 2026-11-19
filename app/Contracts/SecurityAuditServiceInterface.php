<?php

namespace App\Contracts;

use App\Models\SecurityScan;
use App\Models\User;

/**
 * SecurityAuditServiceInterface
 *
 * Contrato del escaneo de seguridad.
 */
interface SecurityAuditServiceInterface
{
    /**
     * Escaneo en curso, si lo hay.
     */
    public function activeScan(): ?SecurityScan;

    /**
     * Crear el escaneo y despachar el job.
     */
    public function start(User $user): SecurityScan;

    /**
     * Marcar un escaneo como detenido.
     */
    public function stop(SecurityScan $scan, User $user): void;

    /**
     * Ejecutar el escaneo completo. Lo llama el job.
     */
    public function run(SecurityScan $scan): void;

    /**
     * Hallazgos locales (sin escaneo ni LLM).
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectFindings(): array;

    /**
     * Severidad local más alta de un conjunto de hallazgos.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function localSeverity(array $findings): string;
}
