<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->decimal('fine_amount', 10, 2)->default(0)->after('renewed_count');
            $table->enum('fine_status', ['none', 'unpaid', 'paid'])->default('none')->after('fine_amount');
        });
    }

    public function down(): void
    {
        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->dropColumn(['fine_amount', 'fine_status']);
        });
    }
};