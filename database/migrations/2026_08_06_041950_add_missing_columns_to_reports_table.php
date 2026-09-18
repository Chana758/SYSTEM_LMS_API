<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('type');                     // borrow | fine | user | revenue | stock
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('format');                    // pdf | excel | csv
            $table->string('status')->default('pending'); // pending | completed | failed
            $table->string('file_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['generated_by']);
            $table->dropColumn(['type', 'generated_by', 'date_from', 'date_to', 'format', 'status', 'file_url']);
        });
    }
};