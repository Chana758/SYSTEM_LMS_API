<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\StoreSettingRequest;
use App\Http\Requests\Setting\UpdateSettingRequest;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    /** Setting groups a librarian can never view or modify. */
    protected array $restrictedGroupsForLibrarian = ['ai_config', 'security_settings'];

    /** List settings (admin, librarian); librarians don't see restricted groups. */
    public function index(Request $request)
    {
        $query = Setting::query();

        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        $user = $request->user()->loadMissing('role');

        if ($user->role?->name === 'librarian') {
            // Keep rows with NULL group (whereNotIn alone would drop them)
            $query->where(function ($q) {
                $q->whereNull('group')
                  ->orWhereNotIn('group', $this->restrictedGroupsForLibrarian);
            });
        }

        return response()->json($query->orderBy('group')->get());
    }

    /** Create a setting (admin only). */
    public function store(StoreSettingRequest $request)
    {
        $this->authorizeAdminOnly($request);

        $setting = Setting::create($request->validated());

        return response()->json([
            'message' => 'Setting created successfully.',
            'setting' => $setting,
        ], 201);
    }

    /** Update a setting (admin only). */
    public function update(UpdateSettingRequest $request, Setting $setting)
    {
        $this->authorizeAdminOnly($request);

        $setting->update($request->validated());

        return response()->json([
            'message' => 'Setting updated successfully.',
            'setting' => $setting->fresh(),
        ]);
    }

    /** Delete a setting (admin only). */
    public function destroy(Request $request, Setting $setting)
    {
        $this->authorizeAdminOnly($request);

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully.',
        ]);
    }

    /** Logo and site name for sidebar and login/register pages (public). */
    public function branding()
    {
        return response()->json($this->buildBrandingPayload());
    }

    /** Update logo, site name and tagline (admin only, multipart/form-data). */
    public function updateBranding(Request $request)
    {
        $this->authorizeAdminOnly($request);

        $validated = $request->validate([
            'site_name'        => ['nullable', 'string', 'max:50'],
            'site_name_accent' => ['nullable', 'string', 'max:50'],
            'site_tagline'     => ['nullable', 'string', 'max:80'],
            'logo'             => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $oldPath = Setting::get('site_logo');
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('logo')->store('branding', 'public');
            Setting::set('site_logo', $path, 'string', 'branding');
        }

        // has() instead of filled() so a field can be cleared
        foreach (['site_name', 'site_name_accent', 'site_tagline'] as $key) {
            if ($request->has($key)) {
                Setting::set($key, (string) ($validated[$key] ?? ''), 'string', 'branding');
            }
        }

        return response()->json([
            'message' => 'Branding updated successfully.',
            'branding' => $this->buildBrandingPayload(),
        ]);
    }

    /** Abort with 403 unless the user is an admin. */
    private function authorizeAdminOnly(Request $request): void
    {
        $role = $request->user()->loadMissing('role')->role?->name;

        if ($role !== 'admin') {
            abort(403, 'Only admins can modify system settings.');
        }
    }

    /** Build the branding response (logo URL, name, accent, tagline) with defaults. */
    private function buildBrandingPayload(): array
    {
        $logoPath = Setting::get('site_logo');

        return [
            'logo_url'         => $logoPath ? asset('storage/' . $logoPath) : null,
            'site_name'        => Setting::get('site_name', 'SMART'),
            'site_name_accent' => Setting::get('site_name_accent', 'NEXUS'),
            'site_tagline'     => Setting::get('site_tagline', 'SMART SYSTEM LMS'),
        ];
    }
}