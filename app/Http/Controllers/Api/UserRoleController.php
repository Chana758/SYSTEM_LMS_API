<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Role;
use App\Models\RoleChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    /** PATCH /api/users/{user}/role - promote / demote a user (admin only). */
    public function update(UpdateUserRoleRequest $request, User $user)
    {
        $actor   = $request->user();
        $data    = $request->validated();
        $newRole = Role::where('name', $data['role'])->firstOrFail();

        return DB::transaction(function () use ($actor, $user, $newRole, $data) {
            $target  = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldRole = Role::find($target->role_id);

            // Admins can't change their own role, so at least one admin always remains
            if ($actor->id === $target->id) {
                return response()->json([
                    'message' => 'You cannot change your own role.',
                ], 422);
            }

            if ($target->role_id === $newRole->id) {
                return response()->json([
                    'message' => 'This user already has that role.',
                ], 422);
            }

            $target->update(['role_id' => $newRole->id]);

            // Keep the old profile for history, just mark it inactive
            $this->deactivateProfile($target, $oldRole?->name);

            // Make sure a profile exists for the new role
            match ($newRole->name) {
                'librarian' => $this->ensureLibrarian($target),
                'member'    => $this->ensureMember($target),
                default     => null, // admin needs no profile row
            };

            // Force a fresh login so the frontend picks up the new role
            $target->tokens()->delete();

            RoleChange::create([
                'user_id'      => $target->id,
                'changed_by'   => $actor->id,
                'from_role_id' => $oldRole?->id,
                'to_role_id'   => $newRole->id,
                'note'         => $data['note'] ?? null,
            ]);

            Notification::create([
                'user_id' => $target->id,
                'title'   => 'Your role has changed',
                'message' => sprintf(
                    'Your role was changed from "%s" to "%s". Please sign in again.',
                    $oldRole?->name ?? 'none',
                    $newRole->name
                ),
                'type'    => 'system',
                'link'    => '/',
                'is_read' => false,
                'sent_at' => now(),
            ]);

            return response()->json([
                'message' => "Role changed to {$newRole->name}.",
                'user'    => $target->fresh()->load('role', 'member', 'librarian'),
            ]);
        });
    }

    /** Mark the profile of the old role as inactive. */
    private function deactivateProfile(User $user, ?string $roleName): void
    {
        if ($roleName === 'librarian') {
            Librarian::where('user_id', $user->id)->update(['status' => 'inactive']);
        } elseif ($roleName === 'member') {
            Member::where('user_id', $user->id)->update(['status' => 'inactive']);
        }
    }

    /** Restore/activate the librarian profile, or create one. */
    private function ensureLibrarian(User $user): void
    {
        // withTrashed: unique constraints still count soft-deleted rows
        $librarian = Librarian::withTrashed()->where('user_id', $user->id)->first();

        if ($librarian) {
            if ($librarian->trashed()) {
                $librarian->restore();
            }
            $librarian->update(['status' => 'active']);
            return;
        }

        Librarian::create([
            'user_id'      => $user->id,
            'employee_id'  => $this->generateEmployeeId(),
            'phone_number' => $user->phone,
            'hire_date'    => now(),
            'status'       => 'active',
        ]);
    }

    /** Restore/activate the member profile, or create one. */
    private function ensureMember(User $user): void
    {
        $member = Member::withTrashed()->where('user_id', $user->id)->first();

        if ($member) {
            if ($member->trashed()) {
                $member->restore();
            }
            $member->update(['status' => 'active']);
            return;
        }

        $type = DB::table('membership_types')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('code') ?? 'student';

        Member::create([
            'user_id'          => $user->id,
            'membership_no'    => $this->generateMembershipNo(),
            'membership_type'  => $type,
            'max_borrow_limit' => 3,
            'join_date'        => now(),
            'status'           => 'active',
        ]);
    }

    /** Generate a unique employee ID (EMP-YYYY-NNNN). */
    private function generateEmployeeId(): string
    {
        do {
            $no = 'EMP-' . date('Y') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Librarian::withTrashed()->where('employee_id', $no)->exists());

        return $no;
    }

    /** Generate a unique membership number (MEM-YYYY-NNNNNN). */
    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::withTrashed()->where('membership_no', $no)->exists());

        return $no;
    }
}