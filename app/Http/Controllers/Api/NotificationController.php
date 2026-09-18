<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a listing of notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id)->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('is_read')) {
            $query->where('is_read', filter_var($request->is_read, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * View a single notification and mark it as read.
     */
    public function show(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);

        if (!$notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json($notification);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markRead(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'Marked as read.', 'data' => $notification->fresh()]);
    }

    /**
     * Mark all notifications for the authenticated user as read.
     */
    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, Notification $notification)
    {
        $this->authorizeOwner($request, $notification);
        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }

    /**
     * Admin/Librarian — send a notification to a specific user or broadcast to all members.
     */
    public function store(Request $request)
    {
        // Server-side guard: only admin/librarian may send/broadcast notifications.
        // Relying on the frontend's `canSend` check alone is not enough.
        $role = $request->user()->role->name ?? null;
        if (!in_array($role, ['admin', 'librarian'], true)) {
            abort(403, 'Only admins or librarians can send notifications.');
        }

        $validated = $request->validate([
            'title'     => ['required', 'string', 'max:255'],
            'message'   => ['required', 'string'],
            'type'      => ['required', 'in:overdue,reservation,fine,system,announcement'],
            'link'      => ['nullable', 'string'],
            'user_id'   => ['nullable', 'exists:users,id'],
            'broadcast' => ['nullable', 'boolean'],
        ]);

        if (!empty($validated['broadcast'])) {
            $memberIds = User::whereHas('role', fn ($q) => $q->where('name', 'member'))
                ->pluck('id');

            $now = now();
            $rows = $memberIds->map(fn ($id) => [
                'user_id'    => $id,
                'title'      => $validated['title'],
                'message'    => $validated['message'],
                'type'       => $validated['type'],
                'link'       => $validated['link'] ?? null,
                'is_read'    => false,
                'sent_at'    => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            Notification::insert($rows);

            return response()->json(['message' => "Notification sent to {$memberIds->count()} member(s)."], 201);
        }

        if (empty($validated['user_id'])) {
            return response()->json(['message' => 'Either user_id or broadcast must be provided.'], 422);
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

    /**
     * Check if the authenticated user owns the notification.
     */
    private function authorizeOwner(Request $request, Notification $notification): void
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'You are not authorized to access this notification.');
        }
    }
}
