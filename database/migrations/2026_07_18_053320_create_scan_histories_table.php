<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_histories', function (Blueprint $table) {
            $table->id();

            // User who performed the scan
            $table->foreignId('user_id')
                  ->constrained()
                  ->restrictOnDelete();

            // The book copy scanned (nullable — might be 'not_found')
            $table->foreignId('book_copy_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();

            // Raw barcode string (indexed for fast searching)
            $table->string('barcode_scanned', 50)->index();

            // Scan intent (indexed for filtering analytical reports)
            $table->enum('scan_type', ['borrow', 'return', 'lookup'])
                  ->default('lookup')
                  ->index();

            // Result of the scan (indexed to identify systemic errors)
            $table->enum('scan_result', ['success', 'not_found', 'error'])
                  ->nullable()
                  ->index();

            // Device or machine identifier used for the scan
            $table->string('device')->nullable();

            $table->timestamps();

            // Index on created_at for chronological audit logs
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_histories');
    }
};
