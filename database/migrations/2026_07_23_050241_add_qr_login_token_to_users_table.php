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
            // We store a HASH of the token (sha256), never the raw value —
            // same principle as the existing hashed `password` column. The
            // raw token only ever exists (a) briefly in memory when we
            // generate it, and (b) printed/encoded on the member's QR card.
            // A leaked database dump alone can never be used to log in.
            $table->string('qr_login_token', 64)->nullable()->unique()->after('remember_token');

            // QR cards are physical and can be lost, photographed, or
            // shared — unlike a password the user can't easily "remember
            // to keep secret". We force periodic expiry instead of letting
            // it live forever, and let the user/admin regenerate on demand
            // (e.g. after reporting a card lost).
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