<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KioskDevice;
use App\Models\Visit;
use App\Services\LoginLogger;
use App\Services\MemberCardService;
use App\Services\VisitService;
use App\Support\VisitSettings;
use Illuminate\Http\Request;

/** The tablet at the library door (role "kiosk"). */
class KioskController extends Controller
{
    public function __construct(
        private VisitService $visits,
        private MemberCardService $cards,
    ) {}

    /** GET /kiosk/me: which kiosk this is, plus library hours and seats. Also a heartbeat. */
    public function me(Request $request)
    {
        $device = $this->device($request);

        return response()->json([
            'device'    => $device->only('id', 'name', 'location', 'mode', 'door_enabled'),
            'settings'  => VisitSettings::all(),
            'occupancy' => $this->visits->occupancy(),
        ]);
    }

    /** POST /kiosk/scan: a card (printed or digital) was scanned. */
    public function scan(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:200']]);
        $device = $this->device($request);

        $card = $this->cards->resolve($request->code);

        if (isset($card['error'])) {
            LoginLogger::record('card_denied', $card['user'] ?? null, false, $card['error'], null, $device->name);

            return response()->json([
                'action'  => 'denied',
                'code'    => $card['error'],
                'message' => __('visits.' . $card['error']),
            ], 422);
        }

        $result = $this->visits->scan($device, $card['user'], $card['method']);

        return response()->json([
            'action'    => $result['action'],
            'code'      => $result['code'],
            'message'   => $result['message'],
            'notice'    => $result['notice'] ?? null,
            // Idea 3: remind at the door (books due soon, unpaid fines)
            'reminders' => in_array($result['action'], ['out', 'not_in', 'already_in'], true)
                ? $this->visits->reminders($card['user'])
                : null,
            'door'      => $result['door'] ?? false,
            'person'    => [
                'name'          => $card['user']->name,
                'name_en'       => $card['user']->member?->name_en,
                'avatar'        => $card['user']->avatar,
                'membership_no' => $card['user']->member?->membership_no,
                'class_name'    => $card['user']->member?->class_name,
            ],
            'visit'     => isset($result['visit']) ? $result['visit']->only('id', 'check_in_at', 'check_out_at', 'minutes') : null,
            'occupancy' => $this->visits->occupancy(),
        ], $result['action'] === 'denied' ? 422 : 200);
    }

    /** POST /kiosk/guest: "I am a guest" instead of the paper sign-in book (B4.6). */
    public function guest(Request $request)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'phone'   => ['nullable', 'string', 'max:30'],
            'purpose' => ['nullable', 'string', 'max:120'],
        ]);

        $visit = $this->visits->guestIn($this->device($request), $data);

        return response()->json([
            'message'   => __('visits.GUEST_WELCOME', ['name' => $visit->guest_name]),
            'visit'     => $visit->only('id', 'guest_name', 'check_in_at'),
            'occupancy' => $this->visits->occupancy(),
        ], 201);
    }

    /** GET /kiosk/guests: guests still inside today, for the "Guest leaving" list. */
    public function guests(Request $request)
    {
        $this->device($request);

        return response()->json(
            Visit::open()->today()->where('visitor_type', 'guest')
                ->latest('check_in_at')
                ->get(['id', 'guest_name', 'check_in_at'])
        );
    }

    /** POST /kiosk/guests/{visit}/out */
    public function guestOut(Request $request, Visit $visit)
    {
        $this->device($request);
        abort_unless($visit->visitor_type === 'guest', 404);

        $visit = $this->visits->checkOut($visit, 'guest');

        return response()->json([
            'message'   => __('visits.GUEST_GOODBYE', ['name' => $visit->guest_name]),
            'occupancy' => $this->visits->occupancy(),
        ]);
    }

    private function device(Request $request): KioskDevice
    {
        $device = KioskDevice::where('user_id', $request->user()->id)->first();
        abort_unless($device, 403, __('visits.NOT_A_KIOSK'));

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $device;
    }
}
