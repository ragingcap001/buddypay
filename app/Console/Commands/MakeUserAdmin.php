<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant or revoke administrator access to a user by phone number.
 *
 *   php artisan users:make-admin 08031234567
 *   php artisan users:make-admin 08031234567 --revoke
 */
class MakeUserAdmin extends Command
{
    protected $signature = 'users:make-admin
                            {phone : Phone number of the user}
                            {--revoke : Remove the admin role instead}';

    protected $description = 'Grant (or with --revoke, remove) the admin role — used to sign in to the /admin dashboard';

    public function handle(): int
    {
        $phone = (string) $this->argument('phone');
        $user = User::where('phone', $phone)->first();

        if ($user === null) {
            $this->error("No user found with phone [{$phone}].");

            return self::FAILURE;
        }

        $role = $this->option('revoke') ? User::ROLE_USER : User::ROLE_ADMIN;

        $user->update(['role' => $role]);

        $this->info("User {$user->phone} ({$user->name}) is now '{$role}'.");

        if ($role === User::ROLE_ADMIN) {
            $this->line('They can now sign in at /admin with their phone number and password.');
        }

        return self::SUCCESS;
    }
}
