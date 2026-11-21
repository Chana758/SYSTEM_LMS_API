<?php

namespace App\Services;

use App\Models\BorrowTransaction;
use App\Models\Member;

/**
 * L1: one place that says whether a member may borrow now.
 * Used by the desk scanner (member card), the borrow API and later self-checkout.
 *
 * Checks, in order: account (AccountStatusService::borrowBlock), unpaid fines,
 * and how many books are already out against the membership type's max_books.
 * Loan length and renewals (A5) are still read by BorrowController.
 */
class LoanPolicy
{
    public function __construct(private AccountStatusService $accountStatus) {}

    /** Numbers the desk shows next to the member's name. */
    public function summary(Member $member): array
    {
        $rules = $member->loanRules();
        $active = $this->activeLoans($member);

        $overdue = BorrowTransaction::where('member_id', $member->id)
            ->where(fn ($q) => $q->where('status', 'overdue')
                ->orWhere(fn ($q2) => $q2->where('status', 'borrowed')->where('due_date', '<', now())))
            ->count();

        $unpaid = (float) $member->fines()->where('fines.status', 'unpaid')->sum('fines.amount');

        return [
            'active_loans'     => $active,
            'max_books'        => (int) $rules['max_books'],
            'loan_days'        => (int) $rules['loan_days'],
            'overdue'          => $overdue,
            'unpaid_fines_khr' => $unpaid,
            'refusal'          => $this->refusal($member, $active, $unpaid),
        ];
    }

    /** Reason this member may not borrow one more book, or null. */
    public function refusal(Member $member, ?int $active = null, ?float $unpaid = null): ?array
    {
        if ($block = $this->accountStatus->borrowBlock($member)) {
            return $block;
        }

        $unpaid ??= (float) $member->fines()->where('fines.status', 'unpaid')->sum('fines.amount');
        if ($unpaid > 0) {
            return [
                'code'    => 'UNPAID_FINES',
                'message' => __('loan.UNPAID_FINES', ['amount' => number_format($unpaid)]),
            ];
        }

        $max = (int) $member->loanRules()['max_books'];
        $active ??= $this->activeLoans($member);
        if ($max > 0 && $active >= $max) {
            return [
                'code'    => 'MAX_BOOKS',
                'message' => __('loan.MAX_BOOKS', ['max' => $max]),
            ];
        }

        return null;
    }

    private function activeLoans(Member $member): int
    {
        return BorrowTransaction::where('member_id', $member->id)
            ->whereIn('status', ['borrowed', 'overdue'])
            ->count();
    }
}
