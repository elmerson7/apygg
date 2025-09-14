<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CorsCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cors:check {--fix : Show suggestions to fix issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check CORS configuration for security issues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Checking CORS Configuration...');
        $this->newLine();

        $environment = app()->environment();
        $allowedOrigins = config('cors.allowed_origins', []);
        $supportsCredentials = config('cors.supports_credentials', false);
        $showFix = $this->option('fix');

        // Si no está definido en config, parsear desde env
        if (empty($allowedOrigins)) {
            $allowedOrigins = $this->parseOriginsFromEnv();
        }

        $this->displayCurrentConfiguration($environment, $allowedOrigins, $supportsCredentials);
        
        $issues = $this->checkForIssues($environment, $allowedOrigins, $supportsCredentials);
        
        if (empty($issues)) {
            $this->info('✅ CORS configuration is secure and properly configured!');
            return 0;
        }

        $this->error('❌ Found ' . count($issues) . ' CORS configuration issue(s):');
        $this->newLine();

        foreach ($issues as $issue) {
            $this->warn("• {$issue['message']}");
            if ($showFix && isset($issue['fix'])) {
                $this->line("  💡 Fix: {$issue['fix']}");
            }
        }

        if ($showFix) {
            $this->newLine();
            $this->info('💡 Run with --fix flag to see suggested solutions');
        }

        return 1;
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
     * Display current CORS configuration.
     */
    private function displayCurrentConfiguration(string $environment, array $allowedOrigins, bool $supportsCredentials): void
    {
        $this->info('📋 Current Configuration:');
        
        $this->table(
            ['Setting', 'Value'],
            [
                ['Environment', $environment],
                ['Supports Credentials', $supportsCredentials ? 'Yes' : 'No'],
                ['Allowed Origins Count', count($allowedOrigins)],
                ['Origins', empty($allowedOrigins) ? 'None configured' : implode(', ', $allowedOrigins)],
            ]
        );
        
        $this->newLine();
    }

    /**
     * Check for configuration issues.
     */
    private function checkForIssues(string $environment, array $allowedOrigins, bool $supportsCredentials): array
    {
        $issues = [];

        // Check 1: Production wildcard
        if (in_array($environment, ['production', 'prod'])) {
            if (in_array('*', $allowedOrigins)) {
                $issues[] = [
                    'message' => 'Production environment using wildcard origin (*)',
                    'fix' => 'Replace * with specific domains in CORS_ALLOWED_ORIGINS'
                ];
            }

            if (empty($allowedOrigins)) {
                $issues[] = [
                    'message' => 'Production environment has no CORS origins configured',
                    'fix' => 'Set CORS_ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com'
                ];
            }

            foreach ($allowedOrigins as $origin) {
                if (str_contains($origin, '*')) {
                    $issues[] = [
                        'message' => "Production origin '{$origin}' contains wildcards",
                        'fix' => 'Replace wildcard patterns with exact domain names'
                    ];
                }
            }
        }

        // Check 2: Credentials with wildcard
        if ($supportsCredentials && in_array('*', $allowedOrigins)) {
            $issues[] = [
                'message' => 'Wildcard origin (*) used with supports_credentials=true (browsers will block)',
                'fix' => 'Use specific domains or set supports_credentials=false'
            ];
        }

        // Check 3: Origins format
        foreach ($allowedOrigins as $origin) {
            if ($origin === '*') {
                continue;
            }

            if (!preg_match('/^https?:\/\//', $origin)) {
                $issues[] = [
                    'message' => "Origin '{$origin}' missing protocol",
                    'fix' => "Add https:// or http:// prefix: https://{$origin}"
                ];
            }

            if (!filter_var($origin, FILTER_VALIDATE_URL)) {
                $issues[] = [
                    'message' => "Origin '{$origin}' is not a valid URL",
                    'fix' => 'Ensure origin follows format: https://domain.com'
                ];
            }
        }

        // Check 4: Localhost in production
        if (in_array($environment, ['production', 'prod'])) {
            foreach ($allowedOrigins as $origin) {
                if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
                    $issues[] = [
                        'message' => "Production environment includes localhost origin: {$origin}",
                        'fix' => 'Remove localhost/127.0.0.1 origins from production'
                    ];
                }
            }
        }

        // Check 5: HTTP in production
        if (in_array($environment, ['production', 'prod'])) {
            foreach ($allowedOrigins as $origin) {
                if (str_starts_with($origin, 'http://')) {
                    $issues[] = [
                        'message' => "Production using insecure HTTP origin: {$origin}",
                        'fix' => 'Use HTTPS instead: ' . str_replace('http://', 'https://', $origin)
                    ];
                }
            }
        }

        return $issues;
    }
}
