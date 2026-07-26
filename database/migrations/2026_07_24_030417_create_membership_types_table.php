<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_types', function (Blueprint $table) {
            $table->id();

            // Machine-readable value stored on members.membership_type
            // (e.g. "student") — unique so two types can't collide.
            $table->string('code', 50)->unique();

            // Human-readable label shown in the UI (e.g. "Student")
            $table->string('label', 100);

            $table->text('description')->nullable();

            // Lets admin hide a type from new-member forms without
            // deleting it (existing members keep their assigned type).
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_types');
    }
};