<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberRequest;
use App\Http\Requests\Member\UpdateMemberRequest;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberController extends Controller
{
    /** List members with search, status and membership type filters. */
    public function index(Request $request)
    {
        $query = Member::query()->with('user');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('membership_no', 'ilike', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'ilike', "%{$search}%")
                         ->orWhere('email', 'ilike', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('membership_type')) {
            $query->where('membership_type', $request->input('membership_type'));
        }

        $members = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($members);
    }

    /** Create a member (user account + member profile). */
    public function store(StoreMemberRequest $request)
    {
        $validated = $request->validated();

        $memberRole = Role::firstOrCreate(['name' => 'member']);

        $member = DB::transaction(function () use ($validated, $memberRole) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone'    => $validated['phone'] ?? null,
                'role_id'  => $memberRole->id,
                'status'   => 'active',
            ]);

            return Member::create([
                'user_id'           => $user->id,
                'membership_no'     => $this->generateMembershipNo(),
                'identity_card_no'  => $validated['identity_card_no'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'membership_type'   => $validated['membership_type'],
                'max_borrow_limit'  => $validated['max_borrow_limit'] ?? 3,
                'address'           => $validated['address'] ?? null,
                'join_date'         => now(),
                'expiry_date'       => $validated['expiry_date'] ?? null,
                'status'            => $validated['status'] ?? 'active',
            ]);
        });

        return response()->json([
            'message' => 'Member created successfully.',
            'member'  => $member->load('user'),
        ], 201);
    }

    /** Show one member with user and role. */
    public function show(Member $member)
    {
        return response()->json($member->load('user.role'));
    }

    /** Update a member, splitting fields between the user and member records. */
    public function update(UpdateMemberRequest $request, Member $member)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $member) {
            // Update User fields (name, email, phone)
            $userFields = array_intersect_key($validated, array_flip(['name', 'email', 'phone']));
            if (! empty($userFields)) {
                $member->user->update($userFields);
            }

            // Update Member fields
            $memberFields = array_diff_key($validated, array_flip(['name', 'email', 'phone']));
            if (! empty($memberFields)) {
                $member->update($memberFields);
            }
        });

        return response()->json([
            'message' => 'Member updated successfully.',
            'member'  => $member->fresh()->load('user'),
        ]);
    }

    /** Soft delete a member. */
    public function destroy(Member $member)
    {
        $member->delete();

        return response()->json(['message' => 'Member deleted successfully.']);
    }

    /** Restore a soft-deleted member. */
    public function restore($id)
    {
        $member = Member::withTrashed()->findOrFail($id);
        $member->restore();

        return response()->json([
            'message' => 'Member restored successfully.',
            'member'  => $member->load('user'),
        ]);
    }

    /** Show the logged-in member's own profile (read-only; only staff can edit it). */
    public function myProfile(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)
            ->with('user:id,name,email,phone,avatar')
            ->first();

        if (! $member) {
            return response()->json([
                'message' => 'No member profile is linked to this account.',
            ], 404);
        }

        return response()->json($member);
    }

    /** Generate a unique membership number like MEM-2026-000123 (includes soft-deleted records). */
    private function generateMembershipNo(): string
    {
        do {
            $no = 'MEM-' . date('Y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Member::withTrashed()->where('membership_no', $no)->exists());

        return $no;
    }
}