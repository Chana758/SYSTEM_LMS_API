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
        Schema::create('librarians', function (Blueprint $table) {
            $table->id();
            
            // Core authentication relationship
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Employment & Identification details
            $table->string('employee_id', 50)->unique()->index();
            $table->string('department', 100)->nullable();
            $table->string('position', 100)->nullable();
            
            // Contact information for internal library communications
            $table->string('phone_number', 20)->nullable();
            
            // Employment tracking
            $table->date('hire_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active');
            
            // Maintenance & History
            $table->softDeletes(); // Keep records for audit purposes instead of hard deletion
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('librarians');
    }
};