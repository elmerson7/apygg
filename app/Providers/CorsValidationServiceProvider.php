<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class CorsValidationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->validateCorsConfiguration();
    }

    /**
     * Validate CORS configuration for security.
     */
    private function validateCorsConfiguration(): void
    {
        $environment = app()->environment();
        $allowedOrigins = config('cors.allowed_origins', []);
        $supportsCredentials = config('cors.supports_credentials', false);

        // Si no está definido CORS_ALLOWED_ORIGINS, usar array vacío
        if (empty($allowedOrigins)) {
            $allowedOrigins = $this->parseOriginsFromEnv();
        }

        // Validaciones críticas
        $this->validateProductionSecurity($environment, $allowedOrigins, $supportsCredentials);
        $this->validateCredentialsWithWildcard($allowedOrigins, $supportsCredentials);
        $this->validateOriginsFormat($allowedOrigins);

        // Log configuración en desarrollo
        if ($environment === 'local' || $environment === 'dev') {
            $this->logCorsConfiguration($allowedOrigins, $supportsCredentials);
        }
    }

    /**
     * Parse origins from environment variable.
     */
    private function parseOriginsFromEnv(): array
    {
        $envOrigins = env('CORS_ALLOWED_ORIGINS', '');
        
        if (empty($envOrigins)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $envOrigins)));
    }

    /**
     * Validate production security settings.
     */
    private function validateProductionSecurity(string $environment, array $allowedOrigins, bool $supportsCredentials): void
    {
        if (!in_array($environment, ['production', 'prod'])) {
            return;
        }

        // En producción no debe usar wildcards
        if (in_array('*', $allowedOrigins)) {
            throw new \RuntimeException(
                'CORS Security Error: Wildcard origin (*) is not allowed in production environment. ' .
                'Please specify exact domains in CORS_ALLOWED_ORIGINS.'
            );
        }

        // En producción debe tener orígenes específicos
        if (empty($allowedOrigins)) {
            throw new \RuntimeException(
                'CORS Security Error: CORS_ALLOWED_ORIGINS must be defined in production environment. ' .
                'Specify allowed domains separated by commas.'
            );
        }

        // Validar que no hay patrones inseguros
        foreach ($allowedOrigins as $origin) {
            if (str_contains($origin, '*')) {
                throw new \RuntimeException(
                    "CORS Security Error: Origin pattern '{$origin}' contains wildcards, which is not allowed in production."
                );
            }
        }
    }

    /**
     * Validate credentials with wildcard origins.
     */
    private function validateCredentialsWithWildcard(array $allowedOrigins, bool $supportsCredentials): void
    {
        if (!$supportsCredentials) {
            return;
        }

        // Con supports_credentials=true, no se pueden usar wildcards
        if (in_array('*', $allowedOrigins)) {
            throw new \RuntimeException(
                'CORS Security Error: Cannot use wildcard origin (*) when supports_credentials is true. ' .
                'Browsers will block requests with credentials. Please specify exact domains.'
            );
        }
    }

    /**
     * Validate origins format.
     */
    private function validateOriginsFormat(array $allowedOrigins): void
    {
        foreach ($allowedOrigins as $origin) {
            if ($origin === '*') {
                continue; // Wildcard is valid syntax (but not secure)
            }

            // Validar que tiene protocolo
            if (!preg_match('/^https?:\/\//', $origin)) {
                Log::warning("CORS Warning: Origin '{$origin}' should include protocol (https:// or http://)");
            }

            // Validar formato básico de URL
            if (!filter_var($origin, FILTER_VALIDATE_URL) && $origin !== '*') {
                Log::warning("CORS Warning: Origin '{$origin}' does not appear to be a valid URL");
            }
        }
    }

    /**
     * Log CORS configuration in development.
     */
    private function logCorsConfiguration(array $allowedOrigins, bool $supportsCredentials): void
    {
        Log::info('CORS Configuration:', [
            'allowed_origins' => $allowedOrigins,
            'supports_credentials' => $supportsCredentials,
            'environment' => app()->environment(),
        ]);

        // Advertencias para desarrollo
        if (in_array('*', $allowedOrigins) && $supportsCredentials) {
            Log::warning(
                'CORS Development Warning: Using wildcard origin (*) with supports_credentials=true. ' .
                'This will NOT work in browsers. Consider using specific origins for testing.'
            );
        }
    }
}
