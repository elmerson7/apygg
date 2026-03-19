<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * UserCreateCommand
 *
 * Command to create users from CLI.
 */
class UserCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create 
                            {name : The user name}
                            {email : The user email}
                            {password? : The user password}
                            {--roles= : Comma-separated list of role names to assign}
                            {--send-email : Send welcome email to the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user from the command line';

    /**
     * Execute the console command.
     */
    public function handle(UserService $userService): int
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $password = $this->argument('password');
        $roles = $this->option('roles');
        $sendEmail = $this->option('send-email');

        // Validate inputs
        if (User::where('email', $email)->exists()) {
            $this->error("The email '{$email}' is already registered.");
            return self::FAILURE;
        }

        // Generate password if not provided
        if (!$password) {
            $password = $this->generatePassword();
            $this->info('Generated password: ' . $password);
        }

        try {
            // Create user data
            $userData = [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ];

            // Create the user
            $user = $userService->create($userData);

            $this->info('User created successfully!');
            $this->line('User ID: ' . $user->id);
            $this->line('Name: ' . $user->name);
            $this->line('Email: ' . $user->email);

            if ($sendEmail) {
                $this->info('Welcome email would be sent here (not implemented in this demo).');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error creating user: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Generate a random password.
     *
     * @return string
     */
    protected function generatePassword(): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*', 8)), 0, 12);
    }
}