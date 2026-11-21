<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * End-of-day cash close: each staff member counts their drawer (៛ and $ separately);
 * the system compares it with the cash payments they recorded. Saved once, never edited.
 */
class CashClosingController extends Controller
{
    /** GET /cash-closing?date=&user_id= - what the drawer should hold (admin may pick another staff). */
    public function show(Request $request)
    {
        [$staff, $date] = $this->target($request);

        return response()->json($this->expected($staff, $date) + [
            'staff'   => $staff->only('id', 'name'),
            'date'    => $date->toDateString(),
            'closing' => CashClosing::where('user_id', $staff->id)->whereDate('date', $date)->first(),
        ]);
    }

    /** POST /cash-closing - save the count for me, for today or an earlier day. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'        => ['required', 'date', 'before_or_equal:today'],
            'counted_khr' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'counted_usd' => ['required', 'numeric', 'min:0', 'max:25000'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        $staff = $request->user();
        $date = Carbon::parse($data['date'])->startOfDay();

        if (CashClosing::where('user_id', $staff->id)->whereDate('date', $date)->exists()) {
            throw ValidationException::withMessages(['date' => __('payments.CLOSING_EXISTS')]);
        }

        $expected = $this->expected($staff, $date);
        $diffKhr = round((float) $data['counted_khr'] - $expected['expected_khr']);
        $diffUsd = round((float) $data['counted_usd'] - $expected['expected_usd'], 2);

        if (($diffKhr != 0 || $diffUsd != 0) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => __('payments.CLOSING_NOTE_REQUIRED')]);
        }

        $closing = CashClosing::create([
            'user_id'        => $staff->id,
            'date'           => $date->toDateString(),
            'payments_count' => $expected['payments_count'],
            'expected_khr'   => $expected['expected_khr'],
            'expected_usd'   => $expected['expected_usd'],
            'counted_khr'    => $data['counted_khr'],
            'counted_usd'    => $data['counted_usd'],
            'diff_khr'       => $diffKhr,
            'diff_usd'       => $diffUsd,
            'note'           => $data['note'] ?? null,
        ]);

        // A difference is reported to every admin.
        if ($diffKhr != 0 || $diffUsd != 0) {
            User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->each(fn (User $admin) => Notification::create([
                'user_id' => $admin->id,
                'title'   => __('payments.CLOSING_DIFF_TITLE'),
                'message' => __('payments.CLOSING_DIFF_BODY', [
                    'name' => $staff->name, 'date' => $date->format('d/m/Y'),
                    'khr'  => ($diffKhr > 0 ? '+' : '') . number_format($diffKhr),
                    'usd'  => ($diffUsd > 0 ? '+' : '') . number_format($diffUsd, 2),
                ]),
                'type'    => 'system',
                'link'    => '/fines/payments',
                'is_read' => false,
                'sent_at' => now(),
            ]));
        }

        return response()->json(['message' => __('payments.CLOSING_SAVED'), 'closing' => $closing], 201);
    }

    /** GET /cash-closings - recent closes (admin: everyone, librarian: own). */
    public function index(Request $request)
    {
        $query = CashClosing::with('user:id,name')->latest('date')->latest('id');

        if ($request->user()->role?->name !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json($query->limit(60)->get());
    }

    /** Cash this staff member took in (and gave back) on that day. */
    private function expected(User $staff, Carbon $date): array
    {
        $cash = Payment::where('method', 'cash')
            ->where('created_by', $staff->id)
            ->whereDate('paid_at', $date);

        $in = (clone $cash)->where('status', Payment::PAID);
        $out = (clone $cash)->where('status', 'refunded');

        $inKhr = (float) (clone $in)->sum('cash_received_khr') - (float) (clone $in)->sum('change_khr');
        $outKhr = (float) (clone $out)->sum('amount_khr');

        return [
            'payments_count' => (clone $cash)->whereIn('status', [Payment::PAID, 'refunded'])->count(),
            'received_khr'   => (float) (clone $in)->sum('cash_received_khr'),
            'received_usd'   => (float) (clone $in)->sum('cash_received_usd'),
            'change_khr'     => (float) (clone $in)->sum('change_khr'),
            'refunded_khr'   => $outKhr,
            'expected_khr'   => round($inKhr - $outKhr),
            'expected_usd'   => round((float) (clone $in)->sum('cash_received_usd'), 2),
        ];
    }

    /** Staff + day to look at. Librarians can only see their own drawer. */
    private function target(Request $request): array
    {
        $request->validate(['date' => ['nullable', 'date'], 'user_id' => ['nullable', 'integer', 'exists:users,id']]);

        $staff = $request->user();
        if ($request->filled('user_id') && $staff->role?->name === 'admin') {
            $staff = User::findOrFail($request->integer('user_id'));
        }

        return [$staff, Carbon::parse($request->input('date', today()->toDateString()))->startOfDay()];
    }
}
