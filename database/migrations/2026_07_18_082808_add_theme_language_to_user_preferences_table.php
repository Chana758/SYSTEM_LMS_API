<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            if (!Schema::hasColumn('user_preferences', 'theme')) {
                $table->enum('theme', ['light', 'dark', 'system'])->default('system')->after('dark_mode');
            }
            if (!Schema::hasColumn('user_preferences', 'language')) {
                $table->string('language', 5)->default('en')->after('theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['theme', 'language']);
        });
    }
};