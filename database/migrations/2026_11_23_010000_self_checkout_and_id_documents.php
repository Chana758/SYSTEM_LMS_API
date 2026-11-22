<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-checkout per membership type (students and teachers yes, externals no by default)
 * and an optional ID-card photo uploaded at registration (deleted once staff decide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->boolean('self_checkout')->default(false)->after('deposit_amount');
        });

        DB::table('membership_types')->whereIn('code', ['student', 'teacher'])->update(['self_checkout' => true]);

        Schema::table('members', function (Blueprint $table) {
            $table->string('id_document')->nullable()->after('identity_card_no');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('id_document');
        });

        Schema::table('membership_types', function (Blueprint $table) {
            $table->dropColumn('self_checkout');
        });
    }
};
