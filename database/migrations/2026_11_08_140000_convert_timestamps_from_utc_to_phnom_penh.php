<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data conversion for switching APP_TIMEZONE from UTC to Asia/Phnom_Penh (UTC+7).
 *
 * Laravel stores datetimes in the app timezone, in columns WITHOUT time zone.
 * Every existing value was written as UTC, so after the switch it would be read
 * 7 hours too early. This adds 7 hours to every "timestamp without time zone"
 * column, so existing rows keep showing the correct local time.
 *
 * - DATE columns (borrow_date, due_date, return_date, join_date, ...) are NOT touched:
 *   they are calendar days, not instants.
 * - The "migrations" table is skipped.
 * - Columns are discovered from the database itself, so nothing is missed.
 *
 * ORDER MATTERS: run this migration and set APP_TIMEZONE=Asia/Phnom_Penh at the same time.
 * Running it twice is prevented by the migrations table (it only runs once).
 */
return new class extends Migration
{
    private const OFFSET = "INTERVAL '7 hours'";

    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        $columns = DB::select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND data_type = 'timestamp without time zone'
              AND table_name <> 'migrations'
            ORDER BY table_name, column_name
        SQL);

        DB::transaction(function () use ($columns, $sign) {
            foreach ($columns as $c) {
                $table  = '"' . str_replace('"', '""', $c->table_name) . '"';
                $column = '"' . str_replace('"', '""', $c->column_name) . '"';

                DB::statement(
                    "UPDATE {$table} SET {$column} = {$column} {$sign} " . self::OFFSET
                    . " WHERE {$column} IS NOT NULL"
                );
            }
        });
    }
};
