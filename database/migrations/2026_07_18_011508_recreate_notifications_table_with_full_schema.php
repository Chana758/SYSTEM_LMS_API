<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The existing "notifications" table was created with an incompatible
     * schema (missing user_id, is_read, etc.), causing "relation does not
     * exist"-style errors when NotificationController queried columns that
     * were never created. Since this feature has no production data yet,
     * we drop the old table and recreate it with the intended full schema.
     */
    public function up(): void
    {
        Schema::dropIfExists('notifications');

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // User who receives the notification
            $table->foreignId('user_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Notification content
            $table->string('title');
            $table->text('message');

            // Notification type (indexed for filtering lists)
            $table->enum('type', [
                'overdue',
                'reservation',
                'fine',
                'system',
                'announcement',
            ])->index();

            // Optional link for redirection (e.g., to a specific transaction)
            $table->string('link')->nullable();

            // Read status (indexed for fast query of unread messages)
            $table->boolean('is_read')
                  ->default(false)
                  ->index();

            // Timestamp when the notification was marked as read
            $table->timestamp('read_at')->nullable();

            // Timestamp when the notification was actually sent
            $table->timestamp('sent_at')->nullable();

            // Additional notes or context
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};