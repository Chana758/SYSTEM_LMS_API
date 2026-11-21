<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\User;
use App\Models\Visit;
use App\Support\VisitSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Check-in / check-out at the library door (B4.4-B4.9). */
class VisitService
{
    public function __construct(
        private AccountStatusService $accountStatus,
        private DoorService $door,
    ) {}

    /**
     * A card was scanned at a kiosk. Decides check-in or check-out, records it,
     * and opens the door when the kiosk has one.
     *
     * @return array{action: string, code: string, message: string, visit?: Visit, door?: bool, notice?: ?array}
     */
    public function scan(KioskDevice $device, User $user, string $method): array
    {
        if ($refusal = $this->entryRefusal($user)) {
            LoginLogger::record('card_denied', $user, false, $refusal['code'], null, $device->name);

            return ['action' => 'denied'] + $refusal;
        }

        return DB::transaction(function () use ($device, $user, $method) {
            // Lock this person's open visit so two quick scans can't both check in.
            $open = Visit::where('user_id', $user->id)
                ->open()
                ->today()
                ->lockForUpdate()
                ->latest('check_in_at')
                ->first();

            $recent = $open && $open->check_in_at->gt(now()->subSeconds(VisitSettings::rescanSeconds()));

            // Entrance-only kiosk, or a second scan right after coming in: still inside.
            if ($open && ($device->mode === 'entrance' || $recent)) {
                return [
                    'action'  => 'already_in',
                    'code'    => 'ALREADY_IN',
                    'message' => __('visits.ALREADY_IN', ['name' => $user->name]),
                    'visit'   => $open,
                    'door'    => $this->door->queueOpen($device, $open),
                ];
            }

            if ($open) {
                $open->update([
                    'check_out_at'     => now(),
                    'check_out_method' => $method,
                ]);

                return [
                    'action'  => 'out',
                    'code'    => 'GOODBYE',
                    'message' => __('visits.GOODBYE', ['name' => $user->name, 'minutes' => $open->minutes]),
                    'visit'   => $open->fresh(),
                    'door'    => $this->door->queueOpen($device, $open),
                ];
            }

            if ($device->mode === 'exit') {
                return [
                    'action'  => 'not_in',
                    'code'    => 'NOT_IN',
                    'message' => __('visits.NOT_IN', ['name' => $user->name]),
                    'door'    => $this->door->queueOpen($device),
                ];
            }

            $visit = Visit::create([
                'user_id'         => $user->id,
                'member_id'       => $user->member?->id,
                'visitor_type'    => $this->visitorType($user),
                'check_in_at'     => now(),
                'check_in_method' => $method,
                'kiosk_device_id' => $device->id,
            ]);

            return [
                'action'  => 'in',
                'code'    => 'WELCOME',
                'message' => __('visits.WELCOME', ['name' => $user->name]),
                'visit'   => $visit,
                'door'    => $this->door->queueOpen($device, $visit),
                // e.g. "you have unpaid fines" - shown, but the person may still come in
                'notice'  => $this->accountStatus->loginNotice($user)
                    ?? ($user->status === 'suspended' ? $this->accountStatus->loginBlock($user) : null),
            ];
        });
    }

    public function guestIn(KioskDevice|null $device, array $data, ?User $staff = null): Visit
    {
        $visit = Visit::create([
            'visitor_type'    => 'guest',
            'guest_name'      => $data['name'],
            'guest_phone'     => $data['phone'] ?? null,
            'guest_purpose'   => $data['purpose'] ?? null,
            'check_in_at'     => now(),
            'check_in_method' => $staff ? 'manual' : 'guest',
            'kiosk_device_id' => $device?->id,
            'created_by'      => $staff?->id,
        ]);

        if ($device) {
            $this->door->queueOpen($device, $visit);
        }

        return $visit;
    }

    public function checkOut(Visit $visit, string $method): Visit
    {
        if (! $visit->check_out_at) {
            $visit->update(['check_out_at' => now(), 'check_out_method' => $method]);
        }

        return $visit->fresh();
    }

    /** Staff checks a member in from the desk (forgot card). */
    public function manualIn(User $user, User $staff): Visit
    {
        $open = Visit::where('user_id', $user->id)->open()->today()->first();
        if ($open) {
            return $open;
        }

        return Visit::create([
            'user_id'         => $user->id,
            'member_id'       => $user->member?->id,
            'visitor_type'    => $this->visitorType($user),
            'check_in_at'     => now(),
            'check_in_method' => 'manual',
            'created_by'      => $staff->id,
        ]);
    }

    /**
     * Closes visits nobody scanned out of: earlier days at that day's closing time,
     * today's once closing time has passed. Returns how many were closed.
     */
    public function closeForgotten(): int
    {
        $closed = 0;

        Visit::open()->where('check_in_at', '<', now()->startOfDay())->orderBy('id')
            ->chunkById(200, function ($visits) use (&$closed) {
                foreach ($visits as $visit) {
                    $closing = VisitSettings::closingOn($visit->check_in_at);
                    $visit->update([
                        'check_out_at'     => $closing->gt($visit->check_in_at) ? $closing : $visit->check_in_at,
                        'check_out_method' => 'auto',
                    ]);
                    $closed++;
                }
            });

        $todayClosing = VisitSettings::closingOn(now());
        if (now()->gte($todayClosing)) {
            $closed += Visit::open()->today()->where('check_in_at', '<=', $todayClosing)->update([
                'check_out_at'     => $todayClosing,
                'check_out_method' => 'auto',
            ]);
        }

        return $closed;
    }

    /** People inside now and free seats. */
    public function occupancy(): array
    {
        $inside = Visit::open()->today()->count();
        $capacity = VisitSettings::capacity();

        return [
            'inside'   => $inside,
            'capacity' => $capacity,
            'free'     => $capacity > 0 ? max(0, $capacity - $inside) : null,
            'open'     => now()->between(
                Carbon::parse(VisitSettings::openTime()),
                Carbon::parse(VisitSettings::closeTime()),
            ),
        ];
    }

    /** Why this person may not come in, or null. Unpaid fines do not stop entry. */
    private function entryRefusal(User $user): ?array
    {
        $role = $user->role?->name;
        if ($role === 'kiosk') {
            return ['code' => 'CARD_UNKNOWN', 'message' => __('visits.CARD_UNKNOWN')];
        }

        if ($user->status === 'inactive') {
            return ['code' => 'ACCOUNT_NOT_ACTIVATED', 'message' => __('account.ACCOUNT_NOT_ACTIVATED')];
        }

        if ($user->status === 'pending') {
            return ['code' => 'ACCOUNT_PENDING', 'message' => __('visits.ACCOUNT_PENDING')];
        }

        if ($role === 'member' && $user->member && $this->accountStatus->isExpired($user->member)) {
            return ['code' => 'MEMBERSHIP_EXPIRED', 'message' => __('visits.MEMBERSHIP_EXPIRED')];
        }

        return null;
    }

    private function visitorType(User $user): string
    {
        return match ($user->role?->name) {
            'admin', 'librarian' => 'staff',
            'member' => in_array($user->member?->membership_type, ['student', 'teacher', 'external'], true)
                ? $user->member->membership_type
                : 'other',
            default => 'other',
        };
    }
}
