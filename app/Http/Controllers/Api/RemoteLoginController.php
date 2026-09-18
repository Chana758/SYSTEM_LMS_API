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

    /**
     * POST /remote-login/session (public)
     * Called by the desktop Login page when the "Use Phone" tab opens.
     *
     * 🆕 Generates a random 6-digit PIN alongside the session id. The
     * PIN is returned ONLY in this response — it is shown on the
     * desktop screen, never encoded into the QR code itself. The
     * phone must submit this PIN together with the scanned card's
     * token before a login is accepted, so merely seeing or
     * photographing the pairing QR (screenshot, video call, glance
     * over a shoulder, etc.) is not enough on its own to complete a
     * login on someone else's behalf — the attacker would also need
     * to know the PIN currently displayed on that specific laptop.
     */
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

    /**
     * GET /remote-login/{sessionId}/status (public, throttled)
     * Called by the phone page to confirm the pairing session is
     * still valid before showing the camera. Intentionally does NOT
     * reveal the PIN — the phone user must read it off the laptop
     * screen themselves.
     */
    public function status(string $sessionId)
    {
        return response()->json(['valid' => Cache::has("remote-login-session:{$sessionId}")]);
    }

    /**
     * POST /remote-login/{sessionId}/submit (public, throttled)
     * Called by the phone once it has scanned the library QR card AND
     * the user has typed in the PIN currently shown on the laptop.
     *
     *  Now requires `pin` in addition to `qr_token`. Wrong PIN does
     * NOT consume the session immediately — it decrements a limited
     * number of attempts (MAX_PIN_ATTEMPTS) so a mistyped PIN doesn't
     * force the user to refresh the QR and start over. Once attempts
     * run out, the session is destroyed outright and a fresh QR/PIN
     * pair is required — this bounds how many PINs someone can brute
     * force against a single session.
     */
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