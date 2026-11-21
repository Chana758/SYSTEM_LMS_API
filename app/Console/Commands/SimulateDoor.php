<?php

namespace App\Console\Commands;

use App\Models\KioskDevice;
use App\Services\DoorService;
use Illuminate\Console\Command;

/**
 * Pretends to be the door controller, for testing without hardware:
 *   php artisan door:simulate 1
 * Prints "OPEN" whenever a check-in would open the real door.
 */
class SimulateDoor extends Command
{
    protected $signature = 'door:simulate {device : Kiosk device id} {--seconds=300 : How long to run}';

    protected $description = 'Act as the door controller of a kiosk and print every "open"';

    public function handle(DoorService $door): int
    {
        $device = KioskDevice::find($this->argument('device'));
        if (! $device) {
            $this->error('No kiosk device with that id.');

            return self::FAILURE;
        }

        if (! $device->door_enabled) {
            $this->warn('Door is turned off for this kiosk. Turn it on in Settings > Kiosk devices.');
        }

        $this->info("Simulating the door of \"{$device->name}\". Scan a card at the kiosk. Ctrl+C to stop.");
        $until = now()->addSeconds((int) $this->option('seconds'));

        while (now()->lt($until)) {
            $result = $door->poll($device->fresh());
            if ($result['open']) {
                $this->line('<fg=green>[' . now()->format('H:i:s') . '] OPEN for ' . $result['pulse_ms'] . ' ms</>');
            }
            sleep(1);
        }

        return self::SUCCESS;
    }
}
