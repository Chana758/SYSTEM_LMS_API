<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Block 1 (Login, Register & accounts).
 * - users.status gets "pending" (registered, waiting for staff approval)
 * - users.email becomes nullable (imported students may have no email)
 * - must_change_password + PIN fields on users
 * - student_id, date_of_birth, class_name on members (Excel import + self-activation)
 * - default auth settings rows
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->string('pin')->nullable()->after('must_change_password');
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0)->after('pin');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive', 'suspended', 'pending'))");
        }

        Schema::table('members', function (Blueprint $table) {
            $table->string('student_id', 30)->nullable()->unique()->after('membership_no');
            $table->date('date_of_birth')->nullable()->after('student_id');
            $table->string('class_name', 50)->nullable()->after('date_of_birth');
        });

        $defaults = [
            ['registration_enabled',           'true',  'boolean', 'Allow people to create an account from the Register page'],
            ['registration_requires_approval', 'true',  'boolean', 'New accounts wait for staff approval before they can borrow or reserve'],
            ['activation_enabled',             'true',  'boolean', 'Imported students can activate their own account with student ID + date of birth'],
            ['session_member_days',            '30',    'number',  'How many days a member stays logged in'],
            ['session_staff_hours',            '8',     'number',  'How many hours a staff login lasts (one shift)'],
            ['idle_lock_minutes',              '15',    'number',  'Lock staff screens after this many idle minutes (0 = off)'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key'         => $key,
                    'value'       => $value,
                    'type'        => $type,
                    'group'       => 'auth_settings',
                    'description' => $description,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'auth_settings')->delete();

        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropColumn(['student_id', 'date_of_birth', 'class_name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE users SET status = 'inactive' WHERE status = 'pending'");
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive', 'suspended'))");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'pin', 'pin_failed_attempts', 'pin_locked_until']);
        });
    }
};
