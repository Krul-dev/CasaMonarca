<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Local Admin',
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Active->value,
                'email_verified_at' => now(),
                'password' => Hash::make('ChangeMeLocal#2026!'),
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
            ]
        );
    }
}
