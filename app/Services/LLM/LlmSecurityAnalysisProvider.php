<?php

namespace App\Services\LLM;

use App\Contracts\SecurityAnalysisProviderInterface;

/** Análisis de escaneos de seguridad con el gateway de IA (caso de uso `security.audit`). */
class LlmSecurityAnalysisProvider implements SecurityAnalysisProviderInterface
{
    public function analyze(array $messages, int $scanId): ?array
    {
        return app(LlmGateway::class)
            ->useCase('security.audit')
            ->module('security')
            ->withMeta(['scan_id' => $scanId])
            ->structured($messages);
    }
}
