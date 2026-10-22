<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/*
 | If app/Console/Kernel.php already exists in your project, do NOT replace it.
 | Only add the ONE line marked "reservations" inside its schedule() method.
 */
class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
{
    // Expire reservations past their pickup/queue deadline, and promote
    // the next member in line.
    $schedule->command('reservations:expire')
        ->dailyAt('00:05')
        ->withoutOverlapping();

    // Send a daily reminder to members with overdue books.
    $schedule->command('notifications:overdue')->dailyAt('08:00');
}

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // Auto-registers app/Console/Commands/ExpireReservations.php
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}