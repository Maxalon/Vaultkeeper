<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'user:create';

    protected $description = 'Interactively create a new Vaultkeeper user';

    public function handle(): int
    {
        $username = $this->ask('Username');
        $email    = $this->ask('Email');
        $password = $this->secret('Password');
        $confirm  = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $validator = Validator::make(
            ['username' => $username, 'email' => $email, 'password' => $password],
            [
                'username' => ['required', 'string', 'max:255', 'unique:users,username'],
                'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::create([
            'username' => $username,
            'email'    => $email,
            'password' => $password,
        ]);

        $this->info("Created user #{$user->id} ({$user->username}).");

        return self::SUCCESS;
    }
}
