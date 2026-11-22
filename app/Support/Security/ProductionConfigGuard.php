<?php

namespace App\Support\Security;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Validación de arranque en producción: rechaza una configuración insegura de fábrica
 * (APP_DEBUG, claves por defecto, Sentry al 100 %). Ver docs/deploy.md («Validación de arranque»).
 *
 * Sin estado: recibe la config y devuelve los problemas (apto para Octane y para tests).
 */
final class ProductionConfigGuard
{
    /** Valores que nunca deben llegar a producción (compose.env.example, .env.example, Meilisearch). El slug del proyecto (`app.slug`) también cuenta como débil. */
    private const WEAK_VALUES = ['', 'secret', 'password', 'changeme', 'masterkey', 'redissecure2024!'];

    /** Comandos de construcción que corren sin el entorno real del servidor. */
    private const BUILD_COMMANDS = [
        'package:discover', 'config:cache', 'config:clear', 'route:cache', 'route:clear', 'view:cache',
        'view:clear', 'event:cache', 'optimize', 'optimize:clear', 'key:generate', 'vendor:publish',
        'storage:link', 'about', 'list', 'help', 'clear-compiled', 'filament:upgrade',
    ];

    /** Comando de consola que no es `artisan` (PHPStan, Pest...): herramienta de desarrollo o CI, no un proceso servido. */
    public const EXTERNAL_TOOL = 'external-tool';

    /**
     * @return list<string>
     */
    public static function problems(Repository $config): array
    {
        $problems = [];

        if ((bool) $config->get('app.debug')) {
            $problems[] = 'APP_DEBUG=true: expone trazas y configuración (pon APP_DEBUG=false)';
        }

        if (str_starts_with((string) $config->get('jwt.algo', 'HS256'), 'HS') && self::isWeak($config, (string) $config->get('jwt.secret'), 32)) {
            $problems[] = 'JWT_SECRET vacío, por defecto o con menos de 32 caracteres';
        }

        $dbConnection = (string) $config->get('database.default');
        $dbPassword = (string) $config->get("database.connections.{$dbConnection}.password", '');
        if ($dbConnection !== 'sqlite' && self::isWeak($config, $dbPassword)) {
            $problems[] = 'DB_PASSWORD vacía o por defecto';
        }

        $redisPassword = $config->get('database.redis.default.password');
        if (is_string($redisPassword) && $redisPassword !== '' && self::isWeak($config, $redisPassword)) {
            $problems[] = 'REDIS_PASSWORD por defecto (la de compose.env.example)';
        }

        if ($config->get('scout.driver') === 'meilisearch'
            && self::isWeak($config, (string) $config->get('scout.meilisearch.key'))) {
            $problems[] = 'MEILISEARCH_KEY vacía o por defecto (masterKey)';
        }

        if ((string) $config->get('sentry.dsn') !== '') {
            foreach (['traces_sample_rate' => 'SENTRY_TRACES_SAMPLE_RATE', 'profiles_sample_rate' => 'SENTRY_PROFILES_SAMPLE_RATE'] as $key => $env) {
                $rate = $config->get("sentry.{$key}");
                if ($rate !== null && (float) $rate >= 1.0) {
                    $problems[] = "{$env}=1.0 (100 %): baja la tasa (p. ej. 0.05) o déjala sin definir";
                }
            }
        }

        return $problems;
    }

    /**
     * Lanza si la config es insegura. Solo actúa en producción y si `app.production_guard` está activo.
     *
     * @throws RuntimeException
     */
    public static function assertSafe(Repository $config, string $environment, ?string $consoleCommand = null): void
    {
        if ($environment !== 'production' || ! $config->get('app.production_guard', true)) {
            return;
        }

        if ($consoleCommand !== null && ($consoleCommand === self::EXTERNAL_TOOL || in_array($consoleCommand, self::BUILD_COMMANDS, true))) {
            return;
        }

        $problems = self::problems($config);
        if ($problems !== []) {
            throw new RuntimeException(
                "Configuración insegura para producción:\n - ".implode("\n - ", $problems)
                ."\n(desactivar solo con PRODUCTION_GUARD=false; ver docs/deploy.md)"
            );
        }
    }

    private static function isWeak(Repository $config, string $value, int $minLength = 1): bool
    {
        $weak = [...self::WEAK_VALUES, strtolower((string) $config->get('app.slug', ''))];

        return in_array(strtolower($value), $weak, true) || strlen($value) < $minLength;
    }
}
