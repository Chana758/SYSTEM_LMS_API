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
        Schema::table('users', function (Blueprint $table) {           
            $table->string('qr_login_token', 64)->nullable()->unique()->after('remember_token');
            $table->timestamp('qr_login_token_expires_at')->nullable()->after('qr_login_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['qr_login_token', 'qr_login_token_expires_at']);
        });
    }
};