<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    /**
     * Groups a librarian is never allowed to see or touch, regardless of
     * which method they call. Kept as one source of truth so index()
     * (read) and update()/destroy() (write) can't drift out of sync.
     */
    protected array $restrictedGroupsForLibrarian = ['ai_config', 'security_settings'];

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

        if ($user->role?->name === 'librarian') {
            $query->whereNotIn('group', $this->restrictedGroupsForLibrarian);
        }

        return response()->json($query->orderBy('group')->get());
    }

    /**
     * POST /settings
     * Accessible by: admin only.
     *
     * FIX: previously had no server-side role check at all — relied
     * entirely on the frontend hiding the "Add Setting" button. A
     * librarian calling this endpoint directly could create any setting,
     * including in restricted groups.
     */
    public function store(Request $request)
    {
        $this->authorizeAdminOnly($request);

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
     * Accessible by: admin only.
     *
     * FIX: previously had no server-side role check at all — the
     * disabled inputs in SystemSettings.vue were the only thing stopping
     * a librarian from editing values, including ones in ai_config /
     * security_settings that they can't even see via index().
     */
    public function update(Request $request, Setting $setting)
    {
        $this->authorizeAdminOnly($request);

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
     * Accessible by: admin only.
     *
     * FIX: previously had no server-side role check at all.
     */
    public function destroy(Request $request, Setting $setting)
    {
        $this->authorizeAdminOnly($request);

        $setting->delete();

        return response()->json([
            'message' => 'Setting deleted successfully.',
        ]);
    }

    /**
     * GET /branding
     * PUBLIC route (no auth). Used by Sidebar (after login) AND by
     * Login/Register pages (before login) to render the logo + site name
     * that the admin configured, instead of a hardcoded image/text.
     */
    public function branding()
    {
        return response()->json($this->buildBrandingPayload());
    }

    /**
     * POST /settings/branding
     * Accessible by: admin only.
     * Accepts multipart/form-data because of the optional `logo` file.
     */
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

        if ($request->filled('site_name')) {
            Setting::set('site_name', $validated['site_name'], 'string', 'branding');
        }
        if ($request->filled('site_name_accent')) {
            Setting::set('site_name_accent', $validated['site_name_accent'], 'string', 'branding');
        }
        if ($request->filled('site_tagline')) {
            Setting::set('site_tagline', $validated['site_tagline'], 'string', 'branding');
        }

        return response()->json([
            'message' => 'Branding updated successfully.',
            'branding' => $this->buildBrandingPayload(),
        ]);
    }

    /**
     * Central admin-only guard. Aborts with 403 for anyone whose role is
     * not 'admin' — librarian included, even though librarian is allowed
     * to read settings via index().
     */
    private function authorizeAdminOnly(Request $request): void
    {
        $role = $request->user()->loadMissing('role')->role?->name;

        if ($role !== 'admin') {
            abort(403, 'Only admins can modify system settings.');
        }
    }

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