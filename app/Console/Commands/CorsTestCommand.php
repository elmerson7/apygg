<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Comando para probar la configuración de CORS
 */
class CorsTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cors:test {origin? : Origen a probar (ej: http://localhost:5173)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probar la configuración de CORS y verificar si un origen está permitido';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Verificando configuración de CORS...');
        $this->newLine();

        // Obtener configuración
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedMethods = config('cors.allowed_methods', []);
        $allowedHeaders = config('cors.allowed_headers', []);
        $exposedHeaders = config('cors.exposed_headers', []);
        $maxAge = config('cors.max_age', 3600);
        $supportsCredentials = config('cors.supports_credentials', true);

        // Mostrar configuración
        $this->info('📋 Configuración actual:');
        $this->table(
            ['Parámetro', 'Valor'],
            [
                ['Orígenes permitidos', empty($allowedOrigins) ? '❌ Ninguno configurado' : implode(', ', $allowedOrigins)],
                ['Métodos permitidos', implode(', ', $allowedMethods)],
                ['Headers permitidos', implode(', ', $allowedHeaders)],
                ['Headers expuestos', implode(', ', $exposedHeaders)],
                ['Max Age', $maxAge.' segundos'],
                ['Supports Credentials', $supportsCredentials ? '✅ Sí' : '❌ No'],
            ]
        );

        // Verificar si hay orígenes configurados
        if (empty($allowedOrigins)) {
            $this->warn('⚠️  No hay orígenes permitidos configurados en ALLOWED_ORIGINS');
            $this->info('   Configura ALLOWED_ORIGINS en tu archivo .env');

            return self::FAILURE;
        }

        // Probar origen específico si se proporciona
        $testOrigin = $this->argument('origin');
        if ($testOrigin) {
            $this->newLine();
            $this->info("🧪 Probando origen: {$testOrigin}");

            $isAllowed = $this->isOriginAllowed($testOrigin, $allowedOrigins);

            if ($isAllowed) {
                $this->info("✅ El origen '{$testOrigin}' está permitido");
            } else {
                $this->error("❌ El origen '{$testOrigin}' NO está permitido");
                $this->info('   Orígenes permitidos: '.implode(', ', $allowedOrigins));
            }
        } else {
            $this->newLine();
            $this->info('💡 Tip: Puedes probar un origen específico ejecutando:');
            $this->comment('   php artisan cors:test http://localhost:5173');
        }

        // Verificaciones de seguridad
        $this->newLine();
        $this->info('🔒 Verificaciones de seguridad:');

        $checks = [];

        // Verificar si hay wildcard en producción
        $env = config('app.env');
        $hasWildcard = in_array('*', $allowedOrigins);

        if ($hasWildcard && $env === 'production') {
            $checks[] = ['❌', 'Wildcard (*) en producción', 'No uses * en producción. Es un riesgo de seguridad.'];
        } elseif ($hasWildcard && $env !== 'production') {
            $checks[] = ['⚠️', 'Wildcard (*) en '.$env, 'Considera usar orígenes específicos para mejor seguridad.'];
        } else {
            $checks[] = ['✅', 'Sin wildcard', 'Orígenes específicos configurados.'];
        }

        // Verificar credenciales con wildcard
        if ($supportsCredentials && $hasWildcard) {
            $checks[] = ['❌', 'Credenciales con wildcard', 'No se pueden usar credenciales con origen *. Especifica orígenes exactos.'];
        } elseif ($supportsCredentials && ! $hasWildcard) {
            $checks[] = ['✅', 'Credenciales configuradas', 'Credenciales habilitadas con orígenes específicos.'];
        }

        // Verificar HTTPS en producción
        if ($env === 'production') {
            $hasHttp = false;
            foreach ($allowedOrigins as $origin) {
                if (str_starts_with($origin, 'http://') && ! str_contains($origin, 'localhost')) {
                    $hasHttp = true;
                    break;
                }
            }

            if ($hasHttp) {
                $checks[] = ['⚠️', 'HTTP en producción', 'Considera usar solo HTTPS en producción.'];
            } else {
                $checks[] = ['✅', 'HTTPS en producción', 'Solo HTTPS configurado.'];
            }
        }

        $this->table(['Estado', 'Verificación', 'Nota'], $checks);

        return self::SUCCESS;
    }

    /**
     * Verificar si un origen está permitido
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        $origin = rtrim($origin, '/');

        foreach ($allowedOrigins as $allowedOrigin) {
            $allowedOrigin = rtrim($allowedOrigin, '/');

            if ($origin === $allowedOrigin) {
                return true;
            }

            if (str_starts_with($allowedOrigin, '*.')) {
                $domain = substr($allowedOrigin, 2);
                if (str_ends_with($origin, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }
}
