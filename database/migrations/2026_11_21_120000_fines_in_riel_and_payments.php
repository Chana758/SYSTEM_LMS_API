<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fines module (Sam):
 * FIN1 - fines were stored in USD (Riel / 4100). KHQR charges in Riel, so store Riel.
 * FIN2 - "khqr" payment method.
 * FIN3 - who waived a fine and when.
 * B5.1 - one payments table for fines, deposits and e-book rentals.
 */
return new class extends Migration
{
    private const OLD_RATE = 4100;

    public function up(): void
    {
        Schema::table('fines', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->default(0)->change();
            $table->foreignId('waived_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable()->after('waived_by');
        });

        Schema::table('borrow_transactions', function (Blueprint $table) {
            $table->decimal('fine_amount', 12, 2)->default(0)->change();
        });

        // USD -> Riel for every existing row (after widening the columns).
        DB::table('fines')->update(['amount' => DB::raw('ROUND(amount * ' . self::OLD_RATE . ')')]);
        DB::table('borrow_transactions')->update(['fine_amount' => DB::raw('ROUND(fine_amount * ' . self::OLD_RATE . ')')]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fines DROP CONSTRAINT IF EXISTS fines_payment_method_check');
            DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_payment_method_check CHECK (payment_method IN ('cash', 'khqr', 'card', 'bank_transfer', 'other'))");
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Sent to the bank; PayWay allows at most 20 characters.
            $table->string('tran_id', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // What is being paid: App\Models\Fine now; deposits and e-book rentals later.
            $table->morphs('payable');
            $table->string('purpose', 30)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KHR');
            $table->string('method', 20)->default('khqr');
            $table->string('provider', 20)->default('payway');
            $table->string('status', 20)->default('pending')->index(); // pending, paid, failed, expired, cancelled
            $table->text('qr_string')->nullable();
            $table->string('apv', 50)->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE fines SET payment_method = 'other' WHERE payment_method = 'khqr'");
            DB::statement('ALTER TABLE fines DROP CONSTRAINT IF EXISTS fines_payment_method_check');
            DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_payment_method_check CHECK (payment_method IN ('cash', 'card', 'bank_transfer', 'other'))");
        }

        Schema::table('fines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waived_by');
            $table->dropColumn('waived_at');
        });

        DB::table('fines')->update(['amount' => DB::raw('ROUND(amount / ' . self::OLD_RATE . ', 2)')]);
        DB::table('borrow_transactions')->update(['fine_amount' => DB::raw('ROUND(fine_amount / ' . self::OLD_RATE . ', 2)')]);
    }
};
