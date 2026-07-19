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
        Schema::create('borrow_transactions', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('book_copy_id')->constrained()->onDelete('restrict'); 
            $table->foreignId('member_id')->constrained()->onDelete('restrict');
            $table->foreignId('librarian_id')->nullable()->constrained('librarians')->nullOnDelete();
            
            // Dates (Added index for report generation)
            $table->date('borrow_date')->index();
            $table->date('due_date')->index();
            $table->date('return_date')->nullable();
            
            // Status
            $table->enum('status', ['borrowed', 'returned', 'overdue', 'lost'])->default('borrowed')->index();
            
            // Logic
            $table->integer('renewed_count')->default(0);
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_transactions');
    }
};
