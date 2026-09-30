<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Runs on every container start: creates the local admin only once and never resets its password.
     * Password: ADMIN_INITIAL_PASSWORD from .env, or a random one written to the log.
     */
    public function run(): void
    {
        if (User::where('username', 'admin')->exists()) {
            return;
        }

        $password = env('ADMIN_INITIAL_PASSWORD') ?: Str::password(16, symbols: false);

        User::create([
            'name'                => 'Administrator',
            'username'            => 'admin',
            'email'               => null,
            'password'            => $password,
            'role'                => 'admin',
            'language_preference' => 'sr',
            'is_active'           => true,
        ]);

        if (!env('ADMIN_INITIAL_PASSWORD')) {
            $this->command?->warn("Local admin created — username: admin, password: {$password}");
        }
    }
}
