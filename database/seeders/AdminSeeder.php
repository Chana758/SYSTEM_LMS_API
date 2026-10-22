<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Creates the 3 base roles (admin, librarian, member)
     * and the default Admin accounts. Safe to run multiple times.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'librarian']);
        Role::firstOrCreate(['name' => 'member']);

        $admins = [
            [
                'name'     => 'Sam Channa',
                'email'    => 'csam26176@gmail.com',
                'password' => '125476',
            ],
            [
                'name'     => 'Channa',
                'email'    => 'channa1254@gmail.com',
                'password' => '27159364',
            ],
        ];

        foreach ($admins as $admin) {
            User::firstOrCreate(
                ['email' => $admin['email']],
                [
                    'name'                => $admin['name'],
                    'password'            => Hash::make($admin['password']),
                    'role_id'             => $adminRole->id,
                    'status'              => 'active',
                    'language_preference' => 'km',
                ]
            );
        }
    }
}