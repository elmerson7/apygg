<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

/**
 * ApiKeyCreateCommand
 *
 * Command to generate API keys from CLI.
 */
class ApiKeyCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-key:create 
                            {userId : The user ID}
                            {name : The API key name}
                            {--scopes= : Comma-separated list of scopes (e.g., user.read,user.write)}
                            {--expires-days= : Number of days until expiration (default: 365)}
                            {--environment= : Environment (live or test, default: live)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new API key from the command line';

    /**
     * Execute the console command.
     */
    public function handle(ApiKeyService $apiKeyService): int
    {
        $userId = $this->argument('userId');
        $name = $this->argument('name');
        $scopes = $this->option('scopes');
        $expiresDays = $this->option('expires-days');
        $environment = $this->option('environment');

        // Validate user exists
        $user = User::find($userId);
        if (!$user) {
            $this->error("User with ID '{$userId}' not found.");
            return self::FAILURE;
        }

        // Parse scopes
        $scopeArray = [];
        if ($scopes) {
            $scopeArray = explode(',', $scopes);
            $scopeArray = array_map('trim', $scopeArray);
            $scopeArray = array_filter($scopeArray); // Remove empty values
        }

        // Validate environment
        if (!in_array($environment, ['live', 'test'])) {
            $this->error("Environment must be either 'live' or 'test'.");
            return self::FAILURE;
        }

        try {
            // Calculate expiration date
            $expiresAt = null;
            if ($expiresDays) {
                $expiresAt = now()->addDays((int)$expiresDays);
            }

            // Create the API key
            $result = $apiKeyService->create(
                $user,
                $name,
                $scopeArray,
                $expiresAt,
                $environment
            );

            $this->info('API key created successfully!');
            $this->line('Name: ' . $name);
            $this->line('Key: ' . $result['key']); // The full key with prefix
            $this->line('ID: ' . $result['apiKey']->id);
            $this->line('User: ' . $user->name . ' (' . $user->email . ')');
            $this->line('Scopes: ' . (empty($scopeArray) ? '[all]' : implode(', ', $scopeArray)));
            $this->line('Environment: ' . $environment);
            if ($expiresAt) {
                $this->line('Expires: ' . $expiresAt->toDateTimeString());
            } else {
                $this->line('Expires: Never');
            }

            $this->warn('Please save this key securely. It will not be shown again.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error creating API key: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}