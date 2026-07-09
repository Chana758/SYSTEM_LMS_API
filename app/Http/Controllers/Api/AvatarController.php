<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    /**
     * Upload or replace the authenticated user's avatar.
     */
    public function update(Request $request)
    {
        // 1. Validate the incoming file
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'], // Max 2MB
        ]);

        $user = $request->user();

        // 2. Delete the old avatar from storage if it exists
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        // 3. Store the new file in the 'avatars' folder within the public disk
        $path = $request->file('avatar')->store('avatars', 'public');

        // 4. Update the avatar path in the database
        $user->update(['avatar' => $path]);

        // 5. Return JSON response
        return response()->json([
            'message' => 'Profile avatar updated successfully.',
            'avatar_url' => Storage::disk('public')->url($path),
            'user' => $user->fresh()->load(['role', 'member', 'librarian']),
        ]);
    }

    /**
     * Remove the authenticated user's avatar.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        // 1. Delete file from storage and clear database field
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        // 2. Return JSON response
        return response()->json([
            'message' => 'Profile avatar removed successfully.',
            'user' => $user->fresh()->load(['role', 'member', 'librarian']),
        ]);
    }
}