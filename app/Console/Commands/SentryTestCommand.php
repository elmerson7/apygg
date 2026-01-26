<?php

namespace App\Console\Commands;

use App\Services\LogService;
use Illuminate\Console\Command;
use Sentry\SentrySdk;
use Sentry\Severity;

class SentryTestCommand extends Command
{
    protected $signature = 'sentry:test';

    protected $description = 'Test Sentry configuration by sending a test event';

    public function handle(): int
    {
        $this->info('Testing Sentry configuration...');
        $this->newLine();

        $dsn = config('sentry.dsn');
        $environment = config('sentry.environment', config('app.env', 'local'));

        if (empty($dsn)) {
            $this->error('Sentry DSN is not configured. Please set SENTRY_LARAVEL_DSN in your .env file.');

            return Command::FAILURE;
        }

        $this->info('DSN: '.substr($dsn, 0, 30).'...');
        $this->info("Environment: {$environment}");
        $this->newLine();

        // En dev, solo se envían eventos critical o superior
        // Para testing, enviaremos con severity fatal (critical) para asegurar que se reciba
        $this->info('Sending test message to Sentry (fatal/critical level)...');

        try {
            if (class_exists(SentrySdk::class)) {
                $eventId = SentrySdk::getCurrentHub()->captureMessage(
                    'Test message from Laravel Artisan command (sentry:test)',
                    Severity::fatal()
                );

                if ($eventId) {
                    $this->info("✓ Test message sent successfully! Event ID: {$eventId}");
                } else {
                    $this->warn('⚠ Message sent but no event ID returned');
                }
            } else {
                $this->error('Sentry SDK is not available');

                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("✗ Failed to send test message: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->newLine();

        // Las excepciones siempre se capturan, pero en dev pueden estar filtradas
        $this->info('Sending test exception to Sentry...');

        try {
            $testException = new \Exception('Test exception from Laravel Artisan command (sentry:test)');
            $eventId = SentrySdk::getCurrentHub()->captureException($testException);

            if ($eventId) {
                $this->info("✓ Test exception sent successfully! Event ID: {$eventId}");
            } else {
                $this->warn('⚠ Exception sent but no event ID returned');
            }
        } catch (\Exception $e) {
            $this->error("✗ Failed to send test exception: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->newLine();

        $this->info('Testing LogService integration...');

        try {
            LogService::log('info', 'Test log from SentryTestCommand', [
                'source' => 'artisan_command',
                'command' => 'sentry:test',
            ]);
            $this->info('✓ LogService test completed');
        } catch (\Exception $e) {
            $this->warn("⚠ LogService test failed: {$e->getMessage()}");
        }

        $this->newLine();
        $this->info('✓ Sentry test completed! Check your Sentry dashboard to verify the events were received.');
        $this->info('Note: It may take a few seconds for events to appear in Sentry.');

        return Command::SUCCESS;
    }
}
