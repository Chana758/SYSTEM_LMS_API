<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /** Creates base roles and one admin from env vars. Safe to run repeatedly. */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'librarian']);
        Role::firstOrCreate(['name' => 'member']);

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set; skipping admin creation.');
            return;
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name'                => env('ADMIN_NAME', 'Administrator'),
                'password'            => Hash::make($password),
                'role_id'             => $adminRole->id,
                'status'              => 'active',
                'language_preference' => 'km',
            ]
        );
    }
}