<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The only way to get an admin, who may import torrent and magnet links: no form and no
 * API call can set `is_admin`. Creates the account, or promotes an existing one found by
 * its username or email. The password is typed hidden, never passed on the command line.
 */
#[Signature('user:admin {username : Username of the admin} {email : Email address of the admin}')]
#[Description('Create an admin account, or promote an existing one, able to import torrent and magnet links')]
final class CreateAdmin extends Command
{
    use PasswordValidationRules;

    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $email = (string) $this->argument('email');

        $user = User::query()->where('username', $username)->orWhere('email', $email)->first();

        if ($user !== null) {
            $user->forceFill(['is_admin' => true])->save();
            $this->components->info("{$user->username} is now an admin.");

            return self::SUCCESS;
        }

        $input = [
            'username' => $username,
            'email' => $email,
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Password again'),
        ];

        $validator = Validator::make($input, [
            'username' => ['required', 'lowercase', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'username' => $username,
            'email' => $email,
            'firstname' => 'Admin',
            'lastname' => 'Hypertube',
            'password' => $input['password'],
        ]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        $this->components->info("Admin {$username} created.");

        return self::SUCCESS;
    }
}
