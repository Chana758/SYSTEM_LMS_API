<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (config('demo.enabled')) {
            $this->call(DemoSeeder::class);

            return;
        }

        $this->call([
            AdminSeeder::class,
            MembershipTypeSeeder::class,
        ]);
    }
}