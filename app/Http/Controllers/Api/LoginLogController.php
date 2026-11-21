<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Who logged in, from where, and who failed (admin), plus my own recent logins. */
class LoginLogController extends Controller
{
    /** GET /login-logs?event=&success=&search=&from=&to= (admin) */
    public function index(Request $request)
    {
        $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'success' => ['nullable', 'in:0,1'],
        ]);

        $query = LoginLog::with('user:id,name,email,role_id', 'user.role:id,name');

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }
        if ($request->filled('success')) {
            $query->where('success', $request->success === '1');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->from)->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->to)->endOfDay());
        }
        if ($request->filled('search')) {
            $term = '%' . mb_strtolower($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(identifier) LIKE ?', [$term])
                    ->orWhere('ip', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            });
        }

        return response()->json(
            $query->latest('created_at')->paginate(min(100, (int) $request->get('per_page', 25)))
        );
    }

    /** GET /login-logs/summary (admin): last 24 hours. */
    public function summary()
    {
        $since = now()->subDay();
        $recent = LoginLog::where('created_at', '>=', $since);

        return response()->json([
            'logins'       => (clone $recent)->where('event', 'login')->where('success', true)->count(),
            'failed'       => (clone $recent)->where('success', false)->whereIn('event', ['login_failed', 'login_blocked'])->count(),
            'card_denied'  => (clone $recent)->where('event', 'card_denied')->count(),
            // Many failures from one address in 24 h: worth a look
            'suspicious_ips' => (clone $recent)->where('success', false)
                ->whereNotNull('ip')
                ->selectRaw('ip, COUNT(*) AS failures')
                ->groupBy('ip')->havingRaw('COUNT(*) >= 10')
                ->orderByDesc('failures')->limit(10)->get(),
        ]);
    }

    /** GET /auth/my-login-logs: my last 15 login events. */
    public function mine(Request $request)
    {
        return response()->json(
            LoginLog::where('user_id', $request->user()->id)
                ->whereIn('event', ['login', 'login_failed', 'login_blocked', 'logout_all', 'activate'])
                ->latest('created_at')->limit(15)
                ->get(['id', 'event', 'success', 'reason', 'ip', 'user_agent', 'created_at'])
        );
    }
}
