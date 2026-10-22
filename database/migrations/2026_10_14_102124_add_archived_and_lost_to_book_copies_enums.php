<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE book_copies MODIFY `status` ENUM('available','borrowed','lost','damaged','archived') NOT NULL DEFAULT 'available'");
            DB::statement("ALTER TABLE book_copies MODIFY `condition` ENUM('new','good','worn','damaged','lost') NOT NULL DEFAULT 'new'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE book_copies DROP CONSTRAINT IF EXISTS book_copies_status_check');
            DB::statement("ALTER TABLE book_copies ADD CONSTRAINT book_copies_status_check CHECK (status IN ('available','borrowed','lost','damaged','archived'))");
            DB::statement('ALTER TABLE book_copies DROP CONSTRAINT IF EXISTS book_copies_condition_check');
            DB::statement("ALTER TABLE book_copies ADD CONSTRAINT book_copies_condition_check CHECK (condition IN ('new','good','worn','damaged','lost'))");
        }
    }

    public function down(): void
    {
        // Left empty on purpose: shrinking the enums would fail if rows already use the new values.
    }
};