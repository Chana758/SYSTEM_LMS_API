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
        // Wrap the columns inside Schema::create
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();

            // Relationship
            $table->foreignId('book_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Identification: 
            $table->string('barcode', 50)->unique()->index();

            // Status: Using standard single quotes
            $table->enum('status', ['available', 'borrowed', 'lost', 'damaged'])
                  ->default('available');

            // Condition: Using standard single quotes
            $table->enum('condition', ['new', 'good', 'worn', 'damaged'])
                  ->default('new');
             
            // Additional Info
            $table->string('shelf_location', 50)->nullable(); 
            $table->date('acquired_date')->nullable();        
            $table->text('notes')->nullable();               

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};