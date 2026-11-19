<?php

namespace App\Console\Commands;

use App\Contracts\ApiKeyServiceInterface;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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
                            {--environment= : Environment (live or test, default: live)}
                            {--tenant= : Tenant (id o slug); obligatorio con MULTI_TENANCY_ENABLED=true}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new API key from the command line';

    /**
     * Execute the console command.
     */
    public function handle(ApiKeyServiceInterface $apiKeyService): int
    {
        $userId = $this->argument('userId');
        $name = $this->argument('name');
        $scopes = $this->option('scopes');
        $expiresDays = $this->option('expires-days');
        $environment = $this->option('environment') ?: 'live';

        // Validate user exists
        $user = User::find($userId);
        if (! $user) {
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
        if (! in_array($environment, ['live', 'test'])) {
            $this->error("Environment must be either 'live' or 'test'.");

            return self::FAILURE;
        }

        try {
            // Calculate expiration date
            $expiresAt = null;
            if ($expiresDays) {
                $expiresAt = now()->addDays((int) $expiresDays);
            }

            // Create the API key (con multiempresa, dentro del tenant: la key le pertenece)
            $create = fn () => $apiKeyService->create($user, $name, $scopeArray, $expiresAt, $environment);

            $context = app(TenantContext::class);

            if ($context->enabled()) {
                $identifier = (string) $this->option('tenant');
                $tenant = $identifier === '' ? null : (ctype_digit($identifier)
                    ? Tenant::query()->find((int) $identifier)
                    : Tenant::query()->bySlug($identifier)->first());

                if (! $tenant instanceof Tenant) {
                    $this->error('Con multiempresa activa indica un tenant válido con --tenant=<id|slug>.');

                    return self::FAILURE;
                }

                if (! $user->canEnterTenant($tenant)) {
                    $this->error('El usuario no es miembro activo de ese tenant.');

                    return self::FAILURE;
                }

                $result = $context->run($tenant, $create);
            } else {
                $result = $create();
            }

            $this->info('API key created successfully!');
            $this->line('Name: '.$name);
            $this->line('Key: '.$result['key']); // The full key with prefix
            $this->line('ID: '.$result['api_key']->id);
            $this->line('User: '.$user->name.' ('.$user->email.')');
            $this->line('Scopes: '.(empty($scopeArray) ? '[all]' : implode(', ', $scopeArray)));
            $this->line('Environment: '.$environment);
            if ($expiresAt) {
                $this->line('Expires: '.$expiresAt->toDateTimeString());
            } else {
                $this->line('Expires: Never');
            }

            $this->warn('Please save this key securely. It will not be shown again.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error creating API key: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
