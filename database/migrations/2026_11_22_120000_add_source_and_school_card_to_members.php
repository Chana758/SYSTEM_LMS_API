<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a member came from, and whether they already hold the university's own
 * student card (so the library does not print a second card for them).
 *  - source: import (university Excel), register (signed up themselves), staff (created by hand)
 *  - has_school_card: the university card's QR (student ID) is accepted at the kiosk and desk
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('source', 20)->default('staff')->after('user_id');
            $table->boolean('has_school_card')->default(false)->after('student_id');
            $table->index('source');
        });

        // Existing rows: members with a student ID came from the university list
        // (and hold its card); the rest signed up themselves.
        DB::table('members')->whereNotNull('student_id')->where('student_id', '!=', '')
            ->update(['source' => 'import', 'has_school_card' => true]);
        DB::table('members')->where(fn ($q) => $q->whereNull('student_id')->orWhere('student_id', ''))
            ->update(['source' => 'register']);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'has_school_card']);
        });
    }
};
