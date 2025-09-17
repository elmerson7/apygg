<?php

namespace App\Http\Middleware;

use App\Services\Logging\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WebhookSecurityLogger
{
    /**
     * Handle an incoming webhook request and log security events.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Solo procesar rutas de webhooks
        if (!$this->isWebhookRoute($request)) {
            return $next($request);
        }

        // Validar signature antes del procesamiento
        $this->validateWebhookSignature($request);
        
        // Detectar payloads sospechosos
        $this->detectSuspiciousPayload($request);
        
        $response = $next($request);
        
        // Log errores de procesamiento de webhooks
        $this->logWebhookProcessingErrors($request, $response);
        
        return $response;
    }

    /**
     * Check if this is a webhook route.
     */
    private function isWebhookRoute(Request $request): bool
    {
        $path = $request->getPathInfo();
        return str_contains($path, '/webhook') || 
               str_contains($path, '/callback') ||
               str_contains($path, '/stripe') ||
               str_contains($path, '/twilio') ||
               str_contains($path, '/github');
    }

    /**
     * Validate webhook signature and log suspicious attempts.
     */
    private function validateWebhookSignature(Request $request): void
    {
        $signature = $request->header('X-Signature') ?: 
                    $request->header('X-Hub-Signature') ?: 
                    $request->header('X-Stripe-Signature');
        
        if (!$signature) {
            SecurityLogger::log(
                severity: SecurityLogger::SEVERITY_MEDIUM,
                event: 'webhook_missing_signature',
                userId: null,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'content_type' => $request->header('Content-Type'),
                    'content_length' => $request->header('Content-Length'),
                    'user_agent' => $request->userAgent()
                ],
                request: $request
            );
            return;
        }

        // Validar formato de signature
        if (!$this->isValidSignatureFormat($signature)) {
            SecurityLogger::log(
                severity: SecurityLogger::SEVERITY_HIGH,
                event: 'webhook_invalid_signature_format',
                userId: null,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'invalid_signature' => substr($signature, 0, 50),
                    'signature_length' => strlen($signature)
                ],
                request: $request
            );
        }
    }

    /**
     * Detect suspicious webhook payloads.
     */
    private function detectSuspiciousPayload(Request $request): void
    {
        $payload = $request->getContent();
        $payloadSize = strlen($payload);
        
        // Payload demasiado grande
        if ($payloadSize > 1024 * 1024) { // 1MB
            SecurityLogger::log(
                severity: SecurityLogger::SEVERITY_MEDIUM,
                event: 'webhook_oversized_payload',
                userId: null,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'payload_size_bytes' => $payloadSize,
                    'content_type' => $request->header('Content-Type')
                ],
                request: $request
            );
        }
        
        // Detectar patrones maliciosos en el payload
        $this->detectMaliciousPatterns($request, $payload);
        
        // Detectar estructura JSON inválida
        if ($request->isJson()) {
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                SecurityLogger::log(
                    severity: SecurityLogger::SEVERITY_LOW,
                    event: 'webhook_invalid_json',
                    userId: null,
                    context: [
                        'endpoint' => $request->getPathInfo(),
                        'json_error' => json_last_error_msg(),
                        'payload_preview' => substr($payload, 0, 200)
                    ],
                    request: $request
                );
            }
        }
    }

    /**
     * Detect malicious patterns in webhook payload.
     */
    private function detectMaliciousPatterns(Request $request, string $payload): void
    {
        $maliciousPatterns = [
            '/<script[^>]*>/i' => 'xss_script_tag',
            '/javascript:/i' => 'javascript_protocol',
            '/data:text\/html/i' => 'data_uri_html',
            '/union\s+select/i' => 'sql_injection',
            '/\.\.\//i' => 'path_traversal',
            '/eval\s*\(/i' => 'eval_execution',
            '/exec\s*\(/i' => 'exec_execution',
            '/system\s*\(/i' => 'system_execution',
            '/base64_decode/i' => 'base64_decode_execution'
        ];

        foreach ($maliciousPatterns as $pattern => $type) {
            if (preg_match($pattern, $payload)) {
                SecurityLogger::log(
                    severity: SecurityLogger::SEVERITY_HIGH,
                    event: 'webhook_malicious_payload',
                    userId: null,
                    context: [
                        'endpoint' => $request->getPathInfo(),
                        'malicious_type' => $type,
                        'pattern_matched' => $pattern,
                        'payload_preview' => substr($payload, 0, 300)
                    ],
                    request: $request
                );
                break; // Solo reportar el primer patrón encontrado
            }
        }
    }

    /**
     * Log webhook processing errors.
     */
    private function logWebhookProcessingErrors(Request $request, SymfonyResponse $response): void
    {
        $statusCode = $response->getStatusCode();
        
        // Log errores de procesamiento
        if ($statusCode >= 400) {
            $severity = $this->determineSeverityByStatusCode($statusCode);
            
            SecurityLogger::log(
                severity: $severity,
                event: 'webhook_processing_error',
                userId: null,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'status_code' => $statusCode,
                    'content_type' => $request->header('Content-Type'),
                    'payload_size' => strlen($request->getContent()),
                    'has_signature' => $request->hasHeader('X-Signature') || 
                                     $request->hasHeader('X-Hub-Signature') || 
                                     $request->hasHeader('X-Stripe-Signature')
                ],
                request: $request
            );
        }
        
        // Log webhooks exitosos de fuentes desconocidas
        if ($statusCode === 200 && !$this->isKnownWebhookSource($request)) {
            SecurityLogger::log(
                severity: SecurityLogger::SEVERITY_LOW,
                event: 'webhook_unknown_source',
                userId: null,
                context: [
                    'endpoint' => $request->getPathInfo(),
                    'user_agent' => $request->userAgent(),
                    'content_type' => $request->header('Content-Type')
                ],
                request: $request
            );
        }
    }

    /**
     * Check if signature format is valid.
     */
    private function isValidSignatureFormat(string $signature): bool
    {
        // Formatos comunes de signatures de webhooks
        $validFormats = [
            '/^sha1=[\da-fA-F]{40}$/',          // GitHub
            '/^sha256=[\da-fA-F]{64}$/',        // Some services
            '/^v1=[\da-fA-F]+,t=\d+/',         // Stripe
            '/^[\da-fA-F]{32,}$/'               // Generic hex
        ];
        
        foreach ($validFormats as $format) {
            if (preg_match($format, $signature)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if this is from a known webhook source.
     */
    private function isKnownWebhookSource(Request $request): bool
    {
        $userAgent = $request->userAgent();
        $knownSources = [
            'GitHub-Hookshot',
            'Stripe/',
            'Twilio-Webhook',
            'PayPal-IPN',
            'Shopify/',
            'Slack-Hooks'
        ];
        
        foreach ($knownSources as $source) {
            if (str_contains($userAgent, $source)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Determine severity based on HTTP status code.
     */
    private function determineSeverityByStatusCode(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return SecurityLogger::SEVERITY_MEDIUM;
        } elseif ($statusCode === 401 || $statusCode === 403) {
            return SecurityLogger::SEVERITY_HIGH;
        } elseif ($statusCode >= 400) {
            return SecurityLogger::SEVERITY_LOW;
        }
        
        return SecurityLogger::SEVERITY_LOW;
    }
}
