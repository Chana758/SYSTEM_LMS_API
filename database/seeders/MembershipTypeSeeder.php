<?php

namespace Database\Seeders;

use App\Models\MembershipType;
use Illuminate\Database\Seeder;

class MembershipTypeSeeder extends Seeder
{
 
    public function run(): void
    {
        $defaults = [
            ['code' => 'student', 'label' => 'Student', 'description' => 'Enrolled students of the institution.'],
            ['code' => 'teacher', 'label' => 'Teacher', 'description' => 'Teaching staff and faculty members.'],
            ['code' => 'external', 'label' => 'External', 'description' => 'Members from outside the institution.'],
        ];

        foreach ($defaults as $type) {
            MembershipType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}