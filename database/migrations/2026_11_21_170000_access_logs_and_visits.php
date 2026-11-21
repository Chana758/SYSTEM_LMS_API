<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who logged in (login_logs), who came into the library (visits),
 * the tablets at the door (kiosk_devices) and the "kiosk" role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identifier', 191)->nullable();   // what was typed (email / student ID), for failed logins
            $table->string('event', 32);                     // login, login_failed, login_blocked, activate, logout, logout_all, card_denied
            $table->boolean('success')->default(true);
            $table->string('reason', 64)->nullable();        // e.g. INVALID_LOGIN, ACCOUNT_SUSPENDED
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device', 64)->nullable();        // kiosk name when the event came from a kiosk
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the kiosk's own login account
            $table->string('name', 100);
            $table->string('location', 150)->nullable();
            $table->string('mode', 16)->default('both');      // entrance | exit | both
            $table->boolean('door_enabled')->default(false);
            $table->string('door_key_hash', 64)->nullable();  // SHA-256 of the key the door controller sends
            $table->unsignedInteger('door_pulse_ms')->default(3000);
            $table->timestamp('last_seen_at')->nullable();      // kiosk screen
            $table->timestamp('door_last_seen_at')->nullable(); // door controller
            $table->timestamps();
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_type', 20);                // student, teacher, external, staff, guest, other
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_phone', 30)->nullable();
            $table->string('guest_purpose', 120)->nullable();
            $table->timestamp('check_in_at');
            $table->timestamp('check_out_at')->nullable();
            $table->string('check_in_method', 20);             // card, digital_card, guest, manual
            $table->string('check_out_method', 20)->nullable(); // card, digital_card, guest, manual, auto
            $table->foreignId('kiosk_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->timestamp('door_opened_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'check_out_at']);
            $table->index('check_in_at');
        });

        if (! DB::table('roles')->where('name', 'kiosk')->exists()) {
            DB::table('roles')->insert(['name' => 'kiosk', 'created_at' => now(), 'updated_at' => now()]);
        }

        $defaults = [
            ['library_open_time', '07:00', 'string', 'Library opening time (HH:MM)'],
            ['library_close_time', '17:30', 'string', 'Library closing time (HH:MM); open visits are closed then'],
            ['library_capacity', '40', 'number', 'Number of seats, for "seats free now"'],
            ['visit_rescan_seconds', '60', 'number', 'A second scan within this time is ignored'],
            ['session_kiosk_days', '180', 'number', 'Days a kiosk tablet stays logged in'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => $value, 'type' => $type, 'group' => 'visit_settings',
                    'description' => $description, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
        Schema::dropIfExists('kiosk_devices');
        Schema::dropIfExists('login_logs');

        DB::table('settings')->whereIn('key', [
            'library_open_time', 'library_close_time', 'library_capacity',
            'visit_rescan_seconds', 'session_kiosk_days',
        ])->delete();
        // The kiosk role is kept: users may still point to it.
    }
};
