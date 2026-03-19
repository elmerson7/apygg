<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Role;
use Illuminate\Console\Command;

/**
 * UserRoleAssignCommand
 *
 * Command to assign roles to users from CLI.
 */
class UserRoleAssignCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:assign-role 
                            {userId : The user ID}
                            {role : The role name to assign}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign a role to a user from the command line';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->argument('userId');
        $roleName = $this->argument('role');

        // Find user
        $user = User::find($userId);
        if (!$user) {
            $this->error("User with ID '{$userId}' not found.");
            return self::FAILURE;
        }

        // Find role
        $role = Role::where('name', $roleName)->first();
        if (!$role) {
            $this->error("Role with name '{$roleName}' not found.");
            return self::FAILURE;
        }

        // Check if user already has this role
        if ($user->roles()->where('id', $role->id)->exists()) {
            $this->info("User already has the role '{$roleName}'.");
            return self::SUCCESS;
        }

        try {
            // Assign role
            $user->roles()->attach($role->id);

            $this->info("Role '{$roleName}' assigned successfully to user '{$user->name}' (ID: {$user->id})");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error assigning role: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}