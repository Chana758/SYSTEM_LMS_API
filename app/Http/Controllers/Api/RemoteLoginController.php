<?php

namespace App\Http\Controllers\Api;

use App\Events\RemoteQrScanned;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RemoteLoginController extends Controller
{
    private const SESSION_TTL_MINUTES = 5;
    private const MAX_PIN_ATTEMPTS = 3;

    /** Create a pairing session with a 6-digit PIN that is shown only on the desktop screen. */
    public function createSession()
    {
        $sessionId = (string) Str::uuid();
        $pin = (string) random_int(100000, 999999);

        Cache::put(
            "remote-login-session:{$sessionId}",
            ['pin' => $pin, 'attempts' => 0],
            now()->addMinutes(self::SESSION_TTL_MINUTES)
        );

        return response()->json([
            'session_id' => $sessionId,
            'pin' => $pin,
            'expires_in_minutes' => self::SESSION_TTL_MINUTES,
        ]);
    }

    /** Check that a pairing session is still valid (never reveals the PIN). */
    public function status(string $sessionId)
    {
        return response()->json(['valid' => Cache::has("remote-login-session:{$sessionId}")]);
    }

    /** Verify the PIN (max 3 attempts), then send the scanned QR token to the desktop. */
    public function submitScan(Request $request, string $sessionId)
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'digits:6'],
        ]);

        $key = "remote-login-session:{$sessionId}";
        $session = Cache::get($key);

        if (! $session) {
            return response()->json([
                'message' => 'This session has expired. Refresh the QR code and try again.',
            ], 404);
        }

        // hash_equals guards against timing attacks on the PIN comparison.
        if (! hash_equals((string) $session['pin'], $validated['pin'])) {
            $attempts = ($session['attempts'] ?? 0) + 1;

            if ($attempts >= self::MAX_PIN_ATTEMPTS) {
                Cache::forget($key);

                return response()->json([
                    'message' => 'Too many incorrect PIN attempts. Ask for a new QR code on the computer.',
                    'locked' => true,
                ], 422);
            }

            Cache::put(
                $key,
                ['pin' => $session['pin'], 'attempts' => $attempts],
                now()->addMinutes(self::SESSION_TTL_MINUTES)
            );

            $remaining = self::MAX_PIN_ATTEMPTS - $attempts;

            return response()->json([
                'message' => "Incorrect PIN. {$remaining} attempt(s) left.",
                'locked' => false,
            ], 422);
        }

        Cache::forget($key);

        broadcast(new RemoteQrScanned($sessionId, $validated['qr_token']));

        return response()->json(['message' => 'Sent — check your computer screen.']);
    }
}