<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Demo deployments (DEMO_MODE=true) get demo data only; the real admin is never created there.
     */
    public function run(): void
    {
        if (config('demo.enabled')) {
            $this->call([
                MembershipTypeSeeder::class,
                DemoSeeder::class,
            ]);
            return;
        }

        $this->call([
            AdminSeeder::class,
            MembershipTypeSeeder::class,
        ]);
    }
}