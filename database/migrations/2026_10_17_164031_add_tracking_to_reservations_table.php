<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Audit trail: when did each state change, and who cancelled?
            $table->timestamp('ready_at')->nullable()->after('status');
            $table->timestamp('fulfilled_at')->nullable()->after('ready_at');
            $table->timestamp('cancelled_at')->nullable()->after('fulfilled_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();

            // The queue is always read by (book, status, priority).
            $table->index(['book_id', 'status', 'priority_order'], 'reservations_queue_idx');
            $table->index(['member_id', 'status'], 'reservations_member_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_queue_idx');
            $table->dropIndex('reservations_member_status_idx');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['ready_at', 'fulfilled_at', 'cancelled_at']);
        });
    }
};