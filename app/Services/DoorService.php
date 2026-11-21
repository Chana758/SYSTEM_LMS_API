<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\Visit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Opens the door through a small controller (e.g. ESP32 + relay) next to the kiosk.
 *
 * The controller polls GET /api/door/{device}/poll every second over HTTPS with its key.
 * After an accepted check-in the server leaves an "open" command for ~10 seconds;
 * the next poll takes it and pulses the relay. This works behind the school's router
 * (no port forwarding) and the browser never has to talk to the LAN.
 *
 * Leaving must never depend on this: the exit side keeps a push button / free exit.
 */
class DoorService
{
    private const COMMAND_TTL = 10; // seconds an "open" waits for the controller

    public function queueOpen(KioskDevice $device, ?Visit $visit = null): bool
    {
        if (! $device->door_enabled || ! $device->door_key_hash) {
            return false;
        }

        Cache::put($this->key($device), [
            'visit_id' => $visit?->id,
            'pulse_ms' => $device->door_pulse_ms,
            'at'       => now()->timestamp,
        ], now()->addSeconds(self::COMMAND_TTL));

        return true;
    }

    /** Called by the controller. Returns ['open' => bool, 'pulse_ms' => int]. */
    public function poll(KioskDevice $device): array
    {
        $device->forceFill(['door_last_seen_at' => now()])->saveQuietly();

        $command = Cache::pull($this->key($device));
        if (! $command) {
            return ['open' => false];
        }

        if (! empty($command['visit_id'])) {
            Visit::whereKey($command['visit_id'])->update(['door_opened_at' => now()]);
        }

        return ['open' => true, 'pulse_ms' => (int) $command['pulse_ms']];
    }

    public function checkKey(KioskDevice $device, ?string $key): bool
    {
        return $device->door_key_hash
            && $key
            && hash_equals($device->door_key_hash, hash('sha256', $key));
    }

    /** New key for the controller; only the hash is stored, the key is shown once. */
    public function newKey(KioskDevice $device): string
    {
        $key = Str::random(40);
        $device->forceFill(['door_key_hash' => hash('sha256', $key)])->save();

        return $key;
    }

    private function key(KioskDevice $device): string
    {
        return 'door:open:' . $device->id;
    }
}
