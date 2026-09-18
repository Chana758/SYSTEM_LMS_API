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

    /**
     * POST /remote-scan/session (auth:sanctum)
     * Called by the already-logged-in staff member on the Scanner page
     * (desktop) when they switch to the "Use Phone" tab. Ties the
     * session to their own user id so submitScan() below can confirm
     * the phone scanning is happening on behalf of the same account —
     * unlike remote-login, where nobody is authenticated yet.
     */
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

    /**
     * GET /remote-scan/{sessionId}/status (public, throttled)
     * Called by the phone page to confirm the pairing session (created
     * by the desktop) is still valid before showing the camera.
     */
    public function status(string $sessionId)
    {
        return response()->json(['valid' => Cache::has("remote-scan-session:{$sessionId}")]);
    }

    /**
     * POST /remote-scan/{sessionId}/submit (public, throttled)
     * Called by the phone every time it detects a barcode. Unlike
     * remote-login's submitScan() (which is one-shot — a QR login token
     * consumes the session), this endpoint intentionally does NOT
     * forget the cache key: a stocktake/borrow session on the desktop
     * expects the phone to keep scanning multiple barcodes back-to-back
     * for up to SESSION_TTL_MINUTES, not just once.
     */
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