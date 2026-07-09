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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            
            // Relationship
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Identification & Details
            $table->string('membership_no', 50)->unique()->index(); 
            $table->string('identity_card_no', 30)->nullable();
                        
            // Contact & Classification
            $table->string('emergency_contact', 20)->nullable();
            $table->string('membership_type', 20)->default('student'); // student, teacher, external
            $table->string('address', 255)->nullable();
            
            // Dates & Status
            $table->date('join_date')->default(now());
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'expired'])->default('active');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};