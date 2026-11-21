<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash at the desk in Riel and/or Dollars, change given in Riel,
 * and an end-of-day cash close per staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('cash_received_khr', 12, 2)->default(0)->after('exchange_rate');
            $table->decimal('cash_received_usd', 10, 2)->default(0)->after('cash_received_khr');
            $table->decimal('change_khr', 12, 2)->default(0)->after('cash_received_usd');
        });

        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // staff who counted the drawer
            $table->date('date');
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('expected_khr', 12, 2)->default(0);
            $table->decimal('expected_usd', 10, 2)->default(0);
            $table->decimal('counted_khr', 12, 2)->default(0);
            $table->decimal('counted_usd', 10, 2)->default(0);
            $table->decimal('diff_khr', 12, 2)->default(0);
            $table->decimal('diff_usd', 10, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closings');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cash_received_khr', 'cash_received_usd', 'change_khr']);
        });
    }
};
