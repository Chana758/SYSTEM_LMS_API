<?php

namespace App\Http\Controllers\Api;

use App\Events\RemoteBarcodeScanned;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RemoteScanController extends Controller
{
    private const SESSION_TTL_MINUTES = 15;

    /** Create a phone-scan session tied to the logged-in staff user. */
    public function createSession(Request $request)
    {
        $sessionId = (string) Str::uuid();

        Cache::put(
            "remote-scan-session:{$sessionId}",
            ['user_id' => $request->user()->id],
            now()->addMinutes(self::SESSION_TTL_MINUTES)
        );

        return response()->json([
            'session_id' => $sessionId,
            'expires_in_minutes' => self::SESSION_TTL_MINUTES,
        ]);
    }

    /** Check that a phone-scan session is still valid. */
    public function status(string $sessionId)
    {
        return response()->json(['valid' => Cache::has("remote-scan-session:{$sessionId}")]);
    }

    /** Send a scanned barcode to the desktop; the session stays open for more scans. */
    public function submitScan(Request $request, string $sessionId)
    {
        $validated = $request->validate(['barcode' => ['required', 'string', 'max:255']]);

        if (! Cache::has("remote-scan-session:{$sessionId}")) {
            return response()->json(['message' => 'This session has expired. Refresh the QR code and try again.'], 404);
        }

        broadcast(new RemoteBarcodeScanned($sessionId, $validated['barcode']));

        return response()->json(['message' => 'Sent — check your computer screen.']);
    }
}