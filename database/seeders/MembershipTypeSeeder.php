<?php

namespace Database\Seeders;

use App\Models\MembershipType;
use Illuminate\Database\Seeder;

class MembershipTypeSeeder extends Seeder
{
    /**
     * Seed the default membership types so existing members (already
     * using 'student'/'teacher'/'external' as plain strings on
     * members.membership_type) keep validating correctly once
     * StoreMemberRequest/UpdateMemberRequest switch from a hardcoded
     * Rule::in(...) to exists:membership_types,code.
     */
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