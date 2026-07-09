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
    Schema::table('members', function (Blueprint $table) {
        // Set the maximum number of books a member can borrow
        $table->integer('max_borrow_limit')->default(3)->after('membership_type');
        
        // Enable soft deletes to keep member history instead of permanent deletion
        $table->softDeletes();
        
        // Add a field for administrative notes or remarks
        $table->text('remarks')->nullable()->after('status');
    });
}

public function down(): void
{
    Schema::table('members', function (Blueprint $table) {
        $table->dropColumn(['max_borrow_limit', 'remarks']);
        $table->dropSoftDeletes();
    });
}
};
