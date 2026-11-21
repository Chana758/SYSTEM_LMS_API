<?php

namespace App\Console\Commands;

use App\Services\VisitService;
use Illuminate\Console\Command;

/** B4.7: close visits of people who left without scanning out. */
class CloseForgottenVisits extends Command
{
    protected $signature = 'visits:close-forgotten';

    protected $description = 'Check out visits left open after closing time';

    public function handle(VisitService $visits): int
    {
        $closed = $visits->closeForgotten();
        $this->info("Closed {$closed} visit(s).");

        return self::SUCCESS;
    }
}
