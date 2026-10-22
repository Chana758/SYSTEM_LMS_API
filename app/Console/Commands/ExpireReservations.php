<?php

namespace App\Console\Commands;

use App\Services\ReservationService; // <-- expireOverdue() lives in the SERVICE, not the controller
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire reservations past their expire_date and promote the next member in the queue';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireOverdue();

        $this->info("Successfully processed {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}