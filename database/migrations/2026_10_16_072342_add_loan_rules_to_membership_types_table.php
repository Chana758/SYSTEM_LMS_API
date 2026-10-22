<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('loan_days')->default(14);
            $table->unsignedSmallInteger('max_books')->default(3);
            $table->unsignedSmallInteger('max_renewals')->default(2);
            $table->unsignedSmallInteger('renew_days')->default(7);
            $table->unsignedInteger('fine_per_day')->default(500); // Riel
        });
    }

    public function down(): void
    {
        Schema::table('membership_types', function (Blueprint $table) {
            $table->dropColumn(['loan_days', 'max_books', 'max_renewals', 'renew_days', 'fine_per_day']);
        });
    }
};