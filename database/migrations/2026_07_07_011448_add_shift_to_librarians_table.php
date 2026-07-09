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
        Schema::table('librarians', function (Blueprint $table) {
            $table->enum('shift', [
                'morning',
                'afternoon',
                'evening',
                'full_day'
            ])->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('librarians', function (Blueprint $table) {
            $table->dropColumn('shift');
        });
    }
};
 