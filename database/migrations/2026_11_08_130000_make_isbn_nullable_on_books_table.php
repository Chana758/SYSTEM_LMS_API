<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * StoreBookRequest / UpdateBookRequest allow an empty ISBN ('nullable'),
 * but the column was created NOT NULL, so saving a book without an ISBN
 * crashed with "null value in column isbn violates not-null constraint" (HTTP 500).
 *
 * The UNIQUE index stays: PostgreSQL allows many NULLs in a unique column,
 * so several books without an ISBN are fine, while real ISBNs stay unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE books ALTER COLUMN isbn DROP NOT NULL');
    }

    public function down(): void
    {
        // Rows without an ISBN would block the rollback, so give them a placeholder first.
        DB::statement("UPDATE books SET isbn = CONCAT('NOISBN-', id) WHERE isbn IS NULL");
        DB::statement('ALTER TABLE books ALTER COLUMN isbn SET NOT NULL');
    }
};
