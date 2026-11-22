<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Models\Member;
use App\Models\ScanHistory;
use App\Services\LoanPolicy;
use App\Services\LoginLogger;
use App\Services\MemberCardService;
use Illuminate\Http\Request;

/** B4.3: at the desk, scan the member's card (or type the member / student number) first. */
class ScanMemberController extends Controller
{
    public function __construct(
        private MemberCardService $cards,
        private LoanPolicy $policy,
    ) {}

    /** POST /scan/member {code} */
    public function show(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:200']]);
        $code = trim($request->code);

        $member = null;
        $card = $this->cards->resolve($code);

        if (isset($card['user'])) {
            if (isset($card['error'])) {
                return $this->refuse($card['error'], __('visits.' . $card['error']), $code);
            }
            $member = $card['user']->member;
            if (! $member) {
                return $this->refuse('NOT_A_MEMBER', __('loan.NOT_A_MEMBER'), $code);
            }
        } elseif (($card['error'] ?? null) === 'DIGITAL_CODE_EXPIRED') {
            return $this->refuse('DIGITAL_CODE_EXPIRED', __('visits.DIGITAL_CODE_EXPIRED'), $code);
        } else {
            // Typed by hand: membership number or student ID
            $member = Member::whereRaw('UPPER(membership_no) = ?', [strtoupper($code)])
                ->orWhere('student_id', $code)
                ->first();
        }

        if (! $member) {
            return $this->refuse('MEMBER_NOT_FOUND', __('loan.MEMBER_NOT_FOUND'), $code);
        }

        $member->load('user:id,name,email,avatar,status');

        $this->log($code, 'success', 'member');

        return response()->json([
            'member' => [
                'id'              => $member->id,
                'name'            => $member->user?->name,
                'name_en'         => $member->name_en,
                'gender'          => $member->gender,
                'avatar'          => $member->user?->avatar,
                'membership_no'   => $member->membership_no,
                'student_id'      => $member->student_id,
                'class_name'      => $member->class_name,
                'membership_type' => $member->membership_type,
                'expiry_date'     => $member->expiry_date,
            ],
            'loans' => $this->policy->summary($member),
            'current' => BorrowTransaction::with('bookCopy:id,book_id,barcode', 'bookCopy.book:id,title')
                ->where('member_id', $member->id)
                ->whereIn('status', ['borrowed', 'overdue'])
                ->orderBy('due_date')
                ->get(['id', 'book_copy_id', 'due_date', 'status']),
        ]);
    }

    private function refuse(string $code, string $message, string $scanned)
    {
        $this->log($scanned, 'error', 'member');

        return response()->json(['code' => $code, 'message' => $message], 422);
    }

    /** Same history table as book scans; never stores a full card token. */
    private function log(string $code, string $result, string $type): void
    {
        try {
            ScanHistory::create([
                'user_id'         => request()->user()?->id,
                'barcode_scanned' => strlen($code) > 30 ? substr($code, 0, 8) . '…' : $code,
                'scan_type'       => 'lookup',
                'scan_result'     => $result === 'success' ? 'success' : 'not_found',
                'device'          => 'member-card',
            ]);
        } catch (\Throwable) {
            // Logging must never block the desk.
        }
    }
}
