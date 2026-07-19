<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ReservationController;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    // This signature is used to call the command via CLI and Scheduler
    protected $signature = 'reservations:expire';
    protected $description = 'Expire reservations past their expire_date and promote next in queue';

    public function handle(ReservationController $controller): int
    {
        $count = $controller->expireOverdue();
        $this->info("Successfully processed {$count} expired reservation(s).");
        return Command::SUCCESS;
    }
}