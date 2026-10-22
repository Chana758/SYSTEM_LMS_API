<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    /** Return the logged-in user's preferences, creating a default row if none exists. */
    public function show(Request $request)
    {
        $preference = $request->user()->preference()->firstOrCreate([]);

        return response()->json($preference);
    }

    /** Update the logged-in user's own preferences (dark mode and notification settings). */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'dark_mode' => ['sometimes', 'boolean'],
            'email_notifications' => ['sometimes', 'boolean'],
            'sms_notifications' => ['sometimes', 'boolean'],
            'push_notifications' => ['sometimes', 'boolean'],
            'overdue_alerts' => ['sometimes', 'boolean'],
            'reservation_alerts' => ['sometimes', 'boolean'],
            'promotional_alerts' => ['sometimes', 'boolean'],
        ]);

        try {
            $preference = $request->user()->preference()->firstOrCreate([]);
            $preference->update($validated);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race-condition fallback: fetch the record created by a concurrent request
            $preference = $request->user()->preference()->first();
            $preference->update($validated);
        }

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'preference' => $preference->fresh(),
        ]);
    }
}