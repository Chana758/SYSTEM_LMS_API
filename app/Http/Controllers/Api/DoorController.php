<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Services\DoorService;
use Illuminate\Http\Request;

/** Polled by the door controller (ESP32 + relay). No user login: the device key is the credential. */
class DoorController extends Controller
{
    public function __construct(private DoorService $door) {}

    /** GET /door/{device}/poll   Header: X-Door-Key */
    public function poll(Request $request, int $device)
    {
        $kiosk = KioskDevice::find($device);

        if (! $kiosk || ! $this->door->checkKey($kiosk, $request->header('X-Door-Key'))) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $kiosk->door_enabled) {
            $kiosk->forceFill(['door_last_seen_at' => now()])->saveQuietly();

            return response()->json(['open' => false, 'enabled' => false]);
        }

        return response()->json($this->door->poll($kiosk));
    }
}
