<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates the 3 base roles (admin, librarian, member)
     * and one default Admin account.
     */
    public function run(): void
    {
        // Create all base roles first (safe to run multiple times — firstOrCreate)
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'librarian']);
        Role::firstOrCreate(['name' => 'member']);

        // Create the default Admin account (only if it doesn't exist yet)
        User::firstOrCreate(
            ['email' => 'csam26176@gmail.com'],
            [
                'name' => 'Sam Channa',
                'password' => Hash::make('125476'), 
                'role_id' => $adminRole->id,
                'status' => 'active',
                'language_preference' => 'km',
            ]
        );
    }
}