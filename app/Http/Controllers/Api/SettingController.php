<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /settings
     * Accessible by: admin, librarian
     * Librarian is restricted from sensitive groups (ai_config, security_settings).
     */
    public function index(Request $request)
    {
        $query = Setting::query();

        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        $user = $request->user()->loadMissing('role');

        // FIX: use the null-safe operator (?->) instead of -> so this
        // doesn't crash with a 500 error if the user's role relation
        // is missing/null (e.g. role_id not set, or the role was deleted).
        if ($user->role?->name === 'librarian') {
            $query->whereNotIn('group', ['ai_config', 'security_settings']);
        }

        return response()->json($query->orderBy('group')->get());
    }

    /**
     * POST /settings
     * Accessible by: admin only
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'unique:settings,key'],
            'value' => ['nullable'],
            'type' => ['required', 'in:string,number,boolean,json'],
            'group' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $setting = Setting::create($validated);

        return response()->json([
            'message' => 'Setting created successfully.',
            'setting' => $setting,
        ], 201);
    }

    /**
     * PUT /settings/{setting}
     * Accessible by: admin only
     */
    public function update(Request $request, Setting $setting)
    {
        $validated = $request->validate([
            'value' => ['required'],
        ]);

        $setting->update($validated);

        return response()->json([
            'message' => 'Setting updated successfully.',
            'setting' => $setting->fresh(),
        ]);
    }

    /**
     * DELETE /settings/{setting}
     * Accessible by: admin only
     */
    public function destroy(Setting $setting)
    {
        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully.',
        ]);
    }
} 