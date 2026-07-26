<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MembershipType;
use Illuminate\Http\Request;

class MembershipTypeController extends Controller
{
    /**
     * GET /membership-types
     * Any authenticated user — used by MemberForm.vue's dropdown.
     * ?active_only=1 restricts to types visible in Create/Edit forms;
     * without it, the admin management page sees everything (including
     * disabled types, so it can re-enable them).
     */
    public function index(Request $request)
    {
        $query = MembershipType::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('label')->get());
    }

    /**
     * POST /membership-types
     * Admin only.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:50', 'alpha_dash', 'unique:membership_types,code'],
            'label'       => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        $type = MembershipType::create($validated);

        return response()->json([
            'message' => 'Membership type created successfully.',
            'type' => $type,
        ], 201);
    }

    /**
     * PUT /membership-types/{membershipType}
     * Admin only. `code` is intentionally NOT updatable here — it's
     * the value already stored on existing members.membership_type
     * rows, so renaming it would silently orphan them. Only label,
     * description, and active status can change.
     */
    public function update(Request $request, MembershipType $membershipType)
    {
        $validated = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $membershipType->update($validated);

        return response()->json([
            'message' => 'Membership type updated successfully.',
            'type' => $membershipType->fresh(),
        ]);
    }

    /**
     * DELETE /membership-types/{membershipType}
     * Admin only. Blocks deletion if any member still uses this code,
     * to avoid orphaning existing members' membership_type value —
     * admin should deactivate instead in that case.
     */
    public function destroy(MembershipType $membershipType)
    {
        $inUse = Member::where('membership_type', $membershipType->code)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete: some members are still assigned this type. Deactivate it instead.',
            ], 422);
        }

        $membershipType->delete();

        return response()->json(['message' => 'Membership type deleted successfully.']);
    }
}