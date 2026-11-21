<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B6.2 deposits (payment part by Sam): external members pay a deposit (KHQR or cash)
 * before borrowing, and get it back (minus unpaid fines) when they leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->unsignedInteger('deposit_amount')->default(0)->after('fine_per_day'); // Riel, 0 = no deposit
        });

        // External members pay 20,000 Riel by default; staff can change it on the Membership types page.
        DB::table('membership_types')->where('code', 'external')->update(['deposit_amount' => 20000]);

        Schema::table('members', function (Blueprint $table) {
            $table->decimal('deposit_amount', 12, 2)->default(0)->after('max_borrow_limit');
            $table->string('deposit_status', 20)->default('none')->after('deposit_amount'); // none, unpaid, paid, refunded
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_status');
            $table->decimal('deposit_deducted', 12, 2)->default(0)->after('deposit_paid_at');
            $table->decimal('deposit_refunded', 12, 2)->default(0)->after('deposit_deducted');
            $table->timestamp('deposit_refunded_at')->nullable()->after('deposit_refunded');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['deposit_amount', 'deposit_status', 'deposit_paid_at', 'deposit_deducted', 'deposit_refunded', 'deposit_refunded_at']);
        });

        Schema::table('membership_types', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
