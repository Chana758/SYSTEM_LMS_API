<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pay in Riel or Dollars. Amounts the library owns stay in Riel; a payment remembers
 * what was charged (amount + currency) and its Riel value (amount_khr) for totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount_khr', 12, 2)->default(0)->after('currency');
            $table->decimal('exchange_rate', 10, 2)->nullable()->after('amount_khr'); // Riel per 1 USD at payment time
        });

        DB::table('payments')->update(['amount_khr' => DB::raw('amount')]);

        $defaults = [
            ['exchange_rate_khr_per_usd', '4100', 'number', 'Riel per 1 US dollar, used to show and charge prices in $'],
            ['default_currency', 'KHR', 'string', 'Currency shown first: KHR or USD'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => $value, 'type' => $type, 'group' => 'payment_settings',
                    'description' => $description, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['exchange_rate_khr_per_usd', 'default_currency'])->delete();

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['amount_khr', 'exchange_rate']);
        });
    }
};
