<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;

/**
 * One place that explains an account's state in plain words (A3)
 * and says whether it may borrow or reserve (A1, B2.9).
 *
 * Developer 2's LoanPolicy (block 5) should call borrowBlock() first,
 * then add its own checks (max books, unpaid fines).
 */
class AccountStatusService
{
    /** Reason login is refused, or null when the user may log in. */
    public function loginBlock(User $user): ?array
    {
        return match ($user->status) {
            'inactive'  => $this->reason('ACCOUNT_NOT_ACTIVATED'),
            'suspended' => $this->suspendedReason($user),
            default     => null,
        };
    }

    /**
     * A notice to show after login (the user can still browse books),
     * or null when everything is fine.
     */
    public function loginNotice(User $user): ?array
    {
        if ($user->status === 'pending') {
            return $this->reason('ACCOUNT_PENDING');
        }

        $member = $user->member;
        if ($member && $this->isExpired($member)) {
            return $this->expiredReason($member);
        }

        return null;
    }

    /** Reason this member may not borrow or reserve, or null when allowed. */
    public function borrowBlock(Member $member): ?array
    {
        $user = $member->user;

        if (! $user) {
            return $this->reason('ACCOUNT_NOT_ACTIVATED');
        }

        // "inactive" user = imported from the school list but no app password yet.
        // That is about the app login only: the student stands at the desk (or uses
        // the school card), so the desk may lend. Self-checkout still needs the PIN,
        // which is only set when the student activates the account.
        return match (true) {
            $user->status === 'pending'   => $this->reason('ACCOUNT_PENDING'),
            $member->status === 'inactive' => $this->reason('MEMBER_INACTIVE'),
            $user->status === 'suspended' => $this->suspendedReason($user),
            $this->isExpired($member)     => $this->expiredReason($member),
            $member->deposit_status === 'unpaid' => $this->reason('DEPOSIT_UNPAID', ['amount' => number_format((float) $member->deposit_amount)], 'payments'),
            default                       => null,
        };
    }

    public function isExpired(Member $member): bool
    {
        return $member->status === 'expired'
            || ($member->expiry_date && $member->expiry_date->lt(today()));
    }

    private function suspendedReason(User $user): array
    {
        $unpaid = $user->member
            ? (float) $user->member->fines()->where('fines.status', 'unpaid')->sum('fines.amount')
            : 0;

        return $unpaid > 0
            ? $this->reason('ACCOUNT_SUSPENDED_FINES', ['amount' => number_format($unpaid)])
            : $this->reason('ACCOUNT_SUSPENDED');
    }

    private function expiredReason(Member $member): array
    {
        $date = $member->expiry_date?->format('d/m/Y');

        return $date
            ? $this->reason('MEMBERSHIP_EXPIRED', ['date' => $date])
            : $this->reason('MEMBERSHIP_EXPIRED_NO_DATE');
    }

    private function reason(string $code, array $replace = [], string $file = 'account'): array
    {
        return [
            'code'    => $code,
            'message' => __($file . '.' . $code, $replace),
        ];
    }
}
