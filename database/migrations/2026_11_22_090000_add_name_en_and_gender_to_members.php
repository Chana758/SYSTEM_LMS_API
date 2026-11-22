<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** English name (as on documents) and gender for members; both optional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('name_en', 120)->nullable()->after('student_id');
            $table->string('gender', 10)->nullable()->after('name_en'); // male | female
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'gender']);
        });
    }
};
