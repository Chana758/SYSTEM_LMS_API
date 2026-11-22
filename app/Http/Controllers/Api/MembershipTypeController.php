<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Http\Requests\MembershipType\MembershipTypeRequest;
use App\Models\MembershipType;
use Illuminate\Http\Request;

class MembershipTypeController extends Controller
{
    /** List membership types; with ?active_only=1 return only active ones. */
    public function index(Request $request)
    {
        $query = MembershipType::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('label')->get());
    }

    /** Create a membership type with a unique code. */
    public function store(MembershipTypeRequest $request)
    {
        $validated = $request->validated();

        $type = MembershipType::create($validated);

        return response()->json([
            'message' => 'Membership type created successfully.',
            'type' => $type,
        ], 201);
    }

    /** Update label, description and active status (code is not changeable). */
    public function update(MembershipTypeRequest $request, MembershipType $membershipType)
    {
        // The code is the key members point to, so it never changes.
        $validated = collect($request->validated())->except('code')->all();

        $membershipType->update($validated);

        return response()->json([
            'message' => 'Membership type updated successfully.',
            'type' => $membershipType->fresh(),
        ]);
    }

    /** Delete a type unless members still use it (deactivate it instead). */
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