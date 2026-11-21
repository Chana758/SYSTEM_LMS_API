<?php

namespace App\Services;

use App\Models\Fine;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * B6.2 / B6.4 deposits (Sam: the money part).
 * none -> unpaid (type needs a deposit) -> paid (KHQR or cash) -> refunded (minus unpaid fines).
 */
class DepositService
{
    /** Ask for a deposit if the member's type needs one. Safe to call again. */
    public function require(Member $member): Member
    {
        $amount = (int) (MembershipType::where('code', $member->membership_type)->value('deposit_amount') ?? 0);

        if ($amount > 0 && in_array($member->deposit_status, ['none', 'refunded'], true)) {
            $member->update([
                'deposit_amount'      => $amount,
                'deposit_status'      => 'unpaid',
                'deposit_paid_at'     => null,
                'deposit_deducted'    => 0,
                'deposit_refunded'    => 0,
                'deposit_refunded_at' => null,
            ]);
        }

        return $member->fresh();
    }

    /** Staff took the deposit in cash at the desk. */
    public function recordCash(Member $member, User $staff): Member
    {
        return DB::transaction(function () use ($member, $staff) {
            $member = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->assertUnpaid($member);

            Payment::create([
                'tran_id'      => $this->tranId(),
                'user_id'      => $member->user_id,
                'payable_type' => $member->getMorphClass(),
                'payable_id'   => $member->id,
                'purpose'      => 'deposit',
                'amount'       => $member->deposit_amount,
                'currency'     => 'KHR',
                'method'       => 'cash',
                'provider'     => 'desk',
                'status'       => Payment::PAID,
                'paid_at'      => now(),
                'created_by'   => $staff->id,
            ]);

            $this->markPaid($member);

            return $member->fresh();
        });
    }

    /** Called by PaymentService when a deposit KHQR is confirmed by the bank. */
    public function markPaid(Member $member): void
    {
        if ($member->deposit_status !== 'unpaid') {
            return;
        }

        $member->update(['deposit_status' => 'paid', 'deposit_paid_at' => now()]);

        Notification::create([
            'user_id' => $member->user_id,
            'title'   => __('payments.DEPOSIT_PAID_TITLE'),
            'message' => __('payments.DEPOSIT_PAID_BODY', ['amount' => number_format((float) $member->deposit_amount)]),
            'type'    => 'system',
            'link'    => '/my-profile',
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }

    /** What a refund would look like right now (B6.4: unpaid fines are taken out first). */
    public function refundPreview(Member $member): array
    {
        $unpaid = (float) $this->unpaidFines($member)->sum('amount');
        $deposit = (float) $member->deposit_amount;
        $deducted = min($unpaid, $deposit);

        return [
            'deposit'      => $deposit,
            'unpaid_fines' => $unpaid,
            'deducted'     => $deducted,
            'refund'       => $deposit - $deducted,
            'fines_left'   => $unpaid - $deducted,
        ];
    }

    /** Give the deposit back in cash, paying unpaid fines from it first (oldest first). */
    public function refund(Member $member, User $staff): array
    {
        return DB::transaction(function () use ($member, $staff) {
            $member = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($member->deposit_status !== 'paid') {
                throw ValidationException::withMessages(['deposit' => __('payments.DEPOSIT_NOT_PAID')]);
            }

            $left = (float) $member->deposit_amount;
            $deducted = 0.0;

            foreach ($this->unpaidFines($member)->orderBy('fines.created_at')->lockForUpdate()->get() as $fine) {
                if ($left < (float) $fine->amount) {
                    break; // a fine bigger than what is left stays unpaid
                }
                $fine->update([
                    'status'         => 'paid',
                    'paid_at'        => now(),
                    'payment_method' => 'other',
                    'notes'          => trim(($fine->notes ? $fine->notes . ' | ' : '') . 'Paid from deposit'),
                ]);
                $left -= (float) $fine->amount;
                $deducted += (float) $fine->amount;
            }

            Payment::create([
                'tran_id'      => $this->tranId(),
                'user_id'      => $member->user_id,
                'payable_type' => $member->getMorphClass(),
                'payable_id'   => $member->id,
                'purpose'      => 'deposit_refund',
                'amount'       => $left,
                'currency'     => 'KHR',
                'method'       => 'cash',
                'provider'     => 'desk',
                'status'       => 'refunded',
                'paid_at'      => now(),
                'created_by'   => $staff->id,
            ]);

            $member->update([
                'deposit_status'      => 'refunded',
                'deposit_deducted'    => $deducted,
                'deposit_refunded'    => $left,
                'deposit_refunded_at' => now(),
            ]);

            return ['member' => $member->fresh(), 'deducted' => $deducted, 'refund' => $left];
        });
    }

    public function assertUnpaid(Member $member): void
    {
        if ($member->deposit_status !== 'unpaid' || (float) $member->deposit_amount <= 0) {
            throw ValidationException::withMessages(['deposit' => __('payments.DEPOSIT_NOT_DUE')]);
        }
    }

    private function unpaidFines(Member $member)
    {
        return Fine::where('fines.status', 'unpaid')
            ->whereHas('borrow', fn ($q) => $q->where('member_id', $member->id));
    }

    private function tranId(): string
    {
        do {
            $id = 'LIB' . now()->format('ymdHis') . strtoupper(Str::random(5));
        } while (Payment::where('tran_id', $id)->exists());

        return $id;
    }
}
