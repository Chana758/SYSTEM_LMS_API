<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Visit;
use App\Services\MemberCardService;
use App\Services\VisitService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Who came to the library, for staff (B4.9), plus the member's digital card (B4.2). */
class VisitController extends Controller
{
    public function __construct(private VisitService $visits) {}

    /** GET /visits?from=&to=&type=&inside=1&search= */
    public function index(Request $request)
    {
        [$from, $to] = $this->range($request);

        $query = Visit::with([
            'user:id,name,avatar',
            'member:id,membership_no,class_name,name_en',
            'kioskDevice:id,name',
        ])->whereBetween('check_in_at', [$from, $to]);

        if ($request->filled('type')) {
            $query->where('visitor_type', $request->type);
        }

        if ($request->boolean('inside')) {
            $query->whereNull('check_out_at');
        }

        // Forgot to scan out (closed by the system at closing time)
        if ($request->boolean('auto')) {
            $query->where('check_out_method', 'auto');
        }

        // card | digital_card | school_card | guest | manual
        if ($request->filled('method')) {
            $query->where('check_in_method', $request->input('method'));
        }

        if ($request->filled('search')) {
            $term = '%' . mb_strtolower($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(guest_name) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('member', fn ($m) => $m->whereRaw('LOWER(membership_no) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(name_en) LIKE ?', [$term]));
            });
        }

        return response()->json(
            $query->latest('check_in_at')->paginate(min(100, (int) $request->get('per_page', 20)))
        );
    }

    /** GET /visits/stats?from=&to= : totals, by type, by hour, by day, average stay, inside now. */
    public function stats(Request $request)
    {
        [$from, $to] = $this->range($request);
        $base = fn () => Visit::whereBetween('check_in_at', [$from, $to]);

        $byType = $base()->select('visitor_type', DB::raw('COUNT(*) AS total'))
            ->groupBy('visitor_type')->pluck('total', 'visitor_type');

        $byHour = $base()->select(DB::raw('EXTRACT(HOUR FROM check_in_at)::int AS hour'), DB::raw('COUNT(*) AS total'))
            ->groupBy('hour')->orderBy('hour')->pluck('total', 'hour');

        $byDay = $base()->select(DB::raw('DATE(check_in_at) AS day'), DB::raw('COUNT(*) AS total'))
            ->groupBy('day')->orderBy('day')->pluck('total', 'day');

        $avg = $base()->whereNotNull('check_out_at')
            ->select(DB::raw('AVG(EXTRACT(EPOCH FROM (check_out_at - check_in_at)) / 60) AS minutes'))
            ->value('minutes');

        return response()->json([
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
            'total'       => (int) $byType->sum(),
            'unique'      => (int) $base()->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'by_type'     => $byType,
            'by_hour'     => $byHour,
            'by_day'      => $byDay,
            'avg_minutes' => $avg !== null ? (int) round($avg) : null,
            'auto_closed' => $base()->where('check_out_method', 'auto')->count(),
            'occupancy'   => $this->visits->occupancy(),
        ]);
    }

    /** POST /visits/{visit}/check-out (staff) */
    public function checkOut(Visit $visit)
    {
        return response()->json([
            'message' => __('visits.CHECKED_OUT'),
            'visit'   => $this->visits->checkOut($visit, 'manual'),
        ]);
    }

    /** POST /visits/check-in (staff): a member who forgot the card, or a guest at the desk. */
    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id', 'required_without:guest_name'],
            'guest_name' => ['nullable', 'string', 'max:120', 'required_without:member_id'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_purpose' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($data['member_id'])) {
            $member = Member::with('user.role', 'user.member')->findOrFail($data['member_id']);
            abort_unless($member->user, 422, 'Member has no account.');
            $visit = $this->visits->manualIn($member->user, $request->user());
        } else {
            $visit = $this->visits->guestIn(null, [
                'name'    => $data['guest_name'],
                'phone'   => $data['guest_phone'] ?? null,
                'purpose' => $data['guest_purpose'] ?? null,
            ], $request->user());
        }

        return response()->json(['message' => __('visits.CHECKED_IN'), 'visit' => $visit], 201);
    }

    /** GET /my-card/code (any logged-in member): a QR that changes every minute. */
    public function myCardCode(Request $request, MemberCardService $cards)
    {
        $user = $request->user()->load('member');

        return response()->json($cards->digitalCode($user) + [
            'name'          => $user->name,
            'name_en'       => $user->member?->name_en,
            'membership_no' => $user->member?->membership_no,
            'student_id'    => $user->member?->student_id,
            'class_name'    => $user->member?->class_name,
            'expiry_date'   => $user->member?->expiry_date,
            'avatar'        => $user->avatar,
        ]);
    }

    /** GET /my-visits: my own visits (last 30). */
    public function mine(Request $request)
    {
        return response()->json(
            Visit::where('user_id', $request->user()->id)->latest('check_in_at')->limit(30)
                ->get(['id', 'check_in_at', 'check_out_at', 'check_in_method', 'check_out_method'])
        );
    }

    /** Default: today. Max 366 days. */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : $from->copy()->endOfDay();

        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }
}
