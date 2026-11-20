<?php

namespace App\Contracts;

/**
 * Punto de extensión: quién analiza con IA los hallazgos (ya anonimizados) de un escaneo de seguridad.
 *
 * Sin el módulo `llm` el binding por defecto no analiza (devuelve null) y el escaneo conserva sus hallazgos locales.
 */
interface SecurityAnalysisProviderInterface
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>|null JSON estructurado del análisis, o null si no hay IA disponible
     */
    public function analyze(array $messages, int $scanId): ?array;
}
