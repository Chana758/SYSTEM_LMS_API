<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\NotificationRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** List the logged-in user's notifications with type, read and search filters. */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id)->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('is_read')) {
            $query->where('is_read', filter_var($request->is_read, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('message', 'like', "%{$s}%"));
        }
        $this->applyExclude($query, $request);

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /** Return the unread count, in total and grouped by type. */
    public function unreadCount(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id)->where('is_read', false);
        $this->applyExclude($query, $request);

        $byType = $query->selectRaw('type, COUNT(*) as c')->groupBy('type')->pluck('c', 'type');

        return response()->json([
            'count'   => (int) $byType->sum(),
            'by_type' => $byType,
        ]);
    }

    /** Skip muted types sent by the client as exclude_types[]=fine&exclude_types[]=system. */
    private function applyExclude($query, Request $request): void
    {
        $exclude = array_filter((array) $request->input('exclude_types', []));
        if ($exclude) {
            $query->whereNotIn('type', $exclude);
        }
    }

    /** Show one notification and mark it as read. */
    public function show(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);

        if (!$notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json($notification);
    }

    /** Mark one notification as read. */
    public function markRead(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'Marked as read.', 'data' => $notification->fresh()]);
    }

    /** Mark all of the logged-in user's notifications as read. */
    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /** Delete one notification. */
    public function destroy(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);
        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }

    /** Send a notification to one user, or broadcast it to all members (staff). */
    public function store(NotificationRequest $request)
    {
        $validated = $request->validated();

        if ($validated['broadcast']) {
            $now   = now();
            $count = 0;

            // chunkById keeps memory flat even with thousands of members
            User::whereHas('role', fn ($q) => $q->where('name', 'member'))
                ->select('id')
                ->chunkById(500, function ($users) use ($validated, $now, &$count) {
                    $rows = $users->map(fn ($u) => [
                        'user_id'    => $u->id,
                        'title'      => $validated['title'],
                        'message'    => $validated['message'],
                        'type'       => $validated['type'],
                        'link'       => $validated['link'] ?? null,
                        'is_read'    => false,
                        'sent_at'    => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    Notification::insert($rows);
                    $count += count($rows);
                });

            return response()->json(['message' => "Notification sent to {$count} member(s)."], 201);
        }

        $notification = Notification::create([
            'user_id' => $validated['user_id'],
            'title'   => $validated['title'],
            'message' => $validated['message'],
            'type'    => $validated['type'],
            'link'    => $validated['link'] ?? null,
            'is_read' => false,
            'sent_at' => now(),
        ]);

        return response()->json(['message' => 'Notification sent.', 'data' => $notification], 201);
    }

    /** Abort with 403 unless the logged-in user owns the notification. */
    private function authorizeOwner(Request $request, Notification $notification): void
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            abort(403, 'You are not authorized to access this notification.');
        }
    }
}