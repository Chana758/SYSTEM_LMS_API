<?php

namespace App\Http\Requests\Circulation;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        // NOTE: role() is a belongsTo(Role::class) relationship, so
        // $user->role returns a Role model instance — never compare it
        // directly to a string. Always use $user->role?->name.
        $roleName = $user->role?->name;

        // Admins and Librarians can create reservations on behalf of any member.
        if (in_array($roleName, ['admin', 'librarian'])) {
            return true;
        }

        // Regular members are always authorized to reserve for themselves.
        // The actual member_id used will be resolved server-side in the
        // controller from the authenticated user — we don't require (or
        // trust) a member_id submitted by a member in the request body.
        if ($roleName === 'member') {
            return Member::where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isStaff = in_array($this->user()->role?->name, ['admin', 'librarian']);

        return [
            'book_id' => ['required', 'exists:books,id'],

            // Staff must explicitly choose which member the reservation is for.
            // Members reserving for themselves don't need to submit this field
            // at all — if they do, it will be ignored by the controller.
            'member_id' => [$isStaff ? 'required' : 'nullable', 'exists:members,id'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'book_id.required'   => 'Please select the book you wish to reserve.',
            'book_id.exists'     => 'The selected book could not be found.',
            'member_id.required' => 'Please select a member.',
            'member_id.exists'   => 'The selected member could not be found.',
        ];
    }
}