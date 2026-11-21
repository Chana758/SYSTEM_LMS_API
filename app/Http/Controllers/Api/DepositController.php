<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\DepositService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

/** B6.2 / B6.4 deposits for external members (Sam: payment part). */
class DepositController extends Controller
{
    public function __construct(private DepositService $deposits)
    {
    }

    /** GET /my-deposit - the member's own deposit (shown on My Profile). */
    public function mine(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($this->present($member));
    }

    /** GET /members/{member}/deposit - staff view, with what a refund would be now. */
    public function show(Member $member)
    {
        return response()->json($this->present($member) + [
            'refund_preview' => $member->deposit_status === 'paid' ? $this->deposits->refundPreview($member) : null,
        ]);
    }

    /** POST /members/{member}/deposit/require - staff asks for the deposit of the member's type. */
    public function require(Member $member)
    {
        return response()->json($this->present($this->deposits->require($member)));
    }

    /** POST /members/{member}/deposit/cash - staff took cash at the desk. */
    public function cash(Request $request, Member $member)
    {
        $member = $this->deposits->recordCash($member, $request->user());

        return response()->json(['message' => __('payments.DEPOSIT_CASH_RECORDED')] + $this->present($member));
    }

    /** POST /members/{member}/deposit/khqr - the member (own) or staff (at the desk). */
    public function khqr(Request $request, Member $member, PaymentService $payments, PaymentController $presenter)
    {
        $user = $request->user();
        $isStaff = in_array($user->role?->name, ['admin', 'librarian'], true);

        abort_unless($isStaff || $member->user_id === $user->id, 403);

        $result = $payments->startForDeposit($member->load('user'), $user, $isStaff ? $user : null, $presenter->currency($request));

        return response()->json($presenter->presentPayment($result['payment'], $result['qr_image'], $result['deeplink']), 201);
    }

    /** POST /members/{member}/deposit/refund - give it back in cash, unpaid fines taken out first. */
    public function refund(Request $request, Member $member)
    {
        $result = $this->deposits->refund($member, $request->user());

        return response()->json([
            'message'  => __('payments.DEPOSIT_REFUNDED', [
                'refund'   => number_format($result['refund']),
                'deducted' => number_format($result['deducted']),
            ]),
            'refund'   => $result['refund'],
            'deducted' => $result['deducted'],
        ] + $this->present($result['member']));
    }

    private function present(Member $member): array
    {
        return [
            'member_id'           => $member->id,
            'deposit_amount'      => (float) $member->deposit_amount,
            'deposit_status'      => $member->deposit_status,
            'deposit_paid_at'     => $member->deposit_paid_at,
            'deposit_deducted'    => (float) $member->deposit_deducted,
            'deposit_refunded'    => (float) $member->deposit_refunded,
            'deposit_refunded_at' => $member->deposit_refunded_at,
        ];
    }
}
