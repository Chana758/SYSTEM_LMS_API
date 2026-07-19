<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')
                  ->constrained()
                  ->restrictOnDelete();

            $table->foreignId('member_id')
                  ->constrained()
                  ->restrictOnDelete();

            $table->date('reserved_date');
            $table->date('expire_date');

            $table->enum('status', [
                'pending',
                'ready',
                'fulfilled',
                'cancelled',
                'expired'
            ])->default('pending')->index();

            $table->unsignedInteger('priority_order')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};