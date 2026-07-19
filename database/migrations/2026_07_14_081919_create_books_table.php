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
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // Identification & Basic Info
            $table->string('title')->index(); 
            $table->string('isbn', 20)->unique()->index(); 
            $table->string('author', 150)->index();
            $table->string('publisher', 150)->nullable(); 

            // Relationships
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            // Metadata
            $table->string('language', 10)->nullable(); 
            $table->year('publish_year')->nullable(); 
            $table->string('edition', 20)->nullable(); 
            $table->integer('pages')->nullable(); 
            $table->text('description')->nullable();

            // Inventory & Logistics
            $table->unsignedInteger('total_qty')->default(0);
            $table->unsignedInteger('available_qty')->default(0);
            $table->string('barcode', 100)->unique()->nullable(); 
            $table->string('cover_image')->nullable(); 
            $table->string('shelf_location', 50)->nullable(); 

            // Financial & Status
            $table->decimal('price', 10, 2)->nullable();
            $table->date('acquired_date')->nullable(); 
            $table->enum('status', [
                'available', 
                'damaged', 
                'lost', 
                'archived'
            ])->default('available'); 

            $table->softDeletes(); 
            $table->timestamps(); 
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
