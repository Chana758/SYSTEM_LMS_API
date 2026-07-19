<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fines', function (Blueprint $table) {
            $table->id();

            // Link to borrow_transactions (prevents deletion if a fine is still associated)
            $table->foreignId('borrow_id')
                  ->constrained('borrow_transactions')
                  ->restrictOnDelete();

            // Fine amount (default 0 for data integrity)
            $table->decimal('amount', 10, 2)->default(0);

            // Reason for the fine (indexed for faster lookups)
            $table->enum('reason', ['overdue', 'damaged', 'lost'])->index();

            // Payment status
            $table->enum('status', ['unpaid', 'paid', 'waived'])
                  ->default('unpaid')
                  ->index();

            // Timestamp when the fine was paid
            $table->timestamp('paid_at')->nullable();

            // Payment method used
            $table->enum('payment_method', [
                'cash',
                'card',
                'bank_transfer',
                'other'
            ])->nullable();

            // Additional remarks or notes
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fines');
    }
};