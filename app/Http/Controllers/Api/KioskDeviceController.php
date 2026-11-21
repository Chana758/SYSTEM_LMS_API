<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\DoorService;
use App\Support\VisitSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Admin: kiosk tablets at the door, their door controllers, and library hours. */
class KioskDeviceController extends Controller
{
    public function __construct(private DoorService $door) {}

    public function index()
    {
        return response()->json(
            KioskDevice::with('user:id,email')->orderBy('name')->get()
        );
    }

    /** Creates the kiosk and its own login. The password is shown once. */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $password = $this->newPassword();

        $device = DB::transaction(function () use ($data, $password) {
            $role = Role::firstOrCreate(['name' => 'kiosk']);

            $user = new User([
                'name'     => 'Kiosk: ' . $data['name'],
                'email'    => 'kiosk-' . Str::lower(Str::random(8)) . '@kiosk.local',
                'password' => Hash::make($password),
                'role_id'  => $role->id,
                'status'   => 'active',
            ]);
            $user->forceFill(['must_change_password' => false])->save();

            return KioskDevice::create($data + ['user_id' => $user->id]);
        });

        return response()->json([
            'device'   => $device->load('user:id,email'),
            'login'    => $device->user->email,
            'password' => $password,
        ], 201);
    }

    public function update(Request $request, KioskDevice $kioskDevice)
    {
        $data = $this->validated($request);
        $kioskDevice->update($data);
        $kioskDevice->user?->update(['name' => 'Kiosk: ' . $data['name']]);

        return response()->json($kioskDevice->fresh()->load('user:id,email'));
    }

    /** New password; the tablet is logged out and must log in again. */
    public function resetPassword(KioskDevice $kioskDevice)
    {
        $password = $this->newPassword();
        $kioskDevice->user->forceFill(['password' => Hash::make($password)])->save();
        $kioskDevice->user->tokens()->delete();

        return response()->json(['login' => $kioskDevice->user->email, 'password' => $password]);
    }

    /** New key for the door controller; shown once. */
    public function doorKey(KioskDevice $kioskDevice)
    {
        return response()->json([
            'device_id' => $kioskDevice->id,
            'door_key'  => $this->door->newKey($kioskDevice),
            'poll_url'  => url('/api/door/' . $kioskDevice->id . '/poll'),
        ]);
    }

    /** Admin "test the door" button. */
    public function testDoor(KioskDevice $kioskDevice)
    {
        return response()->json(['queued' => $this->door->queueOpen($kioskDevice)]);
    }

    public function destroy(KioskDevice $kioskDevice)
    {
        DB::transaction(function () use ($kioskDevice) {
            $user = $kioskDevice->user;
            $user?->tokens()->delete();
            $kioskDevice->delete();
            $user?->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }

    public function settings()
    {
        return response()->json(VisitSettings::all());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'library_open_time'    => ['required', 'date_format:H:i'],
            'library_close_time'   => ['required', 'date_format:H:i', 'after:library_open_time'],
            'library_capacity'     => ['required', 'integer', 'min:0', 'max:5000'],
            'visit_rescan_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'session_kiosk_days'   => ['required', 'integer', 'min:1', 'max:365'],
            'kiosk_voice_enabled'  => ['sometimes', 'boolean'],
            'kiosk_voice_name'     => ['sometimes', 'boolean'],
            'kiosk_voice_volume'   => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        foreach ($data as $key => $value) {
            if (in_array($key, ['kiosk_voice_enabled', 'kiosk_voice_name'], true)) {
                Setting::set($key, (bool) $value, 'boolean', 'visit_settings');
                continue;
            }
            $type = in_array($key, ['library_open_time', 'library_close_time'], true) ? 'string' : 'number';
            Setting::set($key, (string) $value, $type, 'visit_settings');
        }

        return response()->json(VisitSettings::all());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'location'      => ['nullable', 'string', 'max:150'],
            'mode'          => ['required', 'in:entrance,exit,both'],
            'door_enabled'  => ['boolean'],
            'door_pulse_ms' => ['nullable', 'integer', 'min:500', 'max:15000'],
        ]) + ['door_enabled' => false, 'door_pulse_ms' => 3000];
    }

    private function newPassword(): string
    {
        return Str::password(12, symbols: false);
    }
}
