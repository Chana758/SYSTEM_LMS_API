<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    protected function isStaff(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'librarian'], true);
    }

    protected function owns(User $user, Reservation $reservation): bool
    {
        return Member::where('user_id', $user->id)
            ->where('id', $reservation->member_id)
            ->exists();
    }

    /** List all reservations / stats. */
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    /** A member may only open their own reservation. */
    public function view(User $user, Reservation $reservation): bool
    {
        return $this->isStaff($user) || $this->owns($user, $reservation);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $this->isStaff($user) || $this->owns($user, $reservation);
    }

    public function fulfill(User $user, Reservation $reservation): bool
    {
        return $this->isStaff($user);
    }
}