<?php

namespace App\Support\Security;

use App\Contracts\SecurityAnalysisProviderInterface;

/** Sin módulo de IA no hay análisis: el escaneo se queda con los hallazgos locales. */
final class NullSecurityAnalysisProvider implements SecurityAnalysisProviderInterface
{
    public function analyze(array $messages, int $scanId): ?array
    {
        return null;
    }
}
