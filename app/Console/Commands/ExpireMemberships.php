<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

/** B2.9: mark memberships whose expiry date has passed as "expired" (renew at the desk). */
class ExpireMemberships extends Command
{
    protected $signature = 'members:expire';

    protected $description = 'Mark members whose expiry_date has passed as expired';

    public function handle(): int
    {
        $count = Member::where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', today())
            ->update(['status' => 'expired']);

        $this->info("Expired {$count} memberships.");

        return self::SUCCESS;
    }
}
