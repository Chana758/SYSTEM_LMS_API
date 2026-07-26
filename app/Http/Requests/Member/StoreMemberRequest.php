<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ensure your middleware (role:admin,librarian) is applied
        // to the route to handle authorization.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:100'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'phone'             => ['nullable', 'string', 'max:20'],
            'identity_card_no'  => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:20'],
            // FIX: was 'in:student,teacher,external' (hardcoded, required
            // a deploy to add a new type). Now validates against the
            // membership_types master table's `code` column — any type
            // an admin adds via the Membership Types settings page
            // passes validation automatically.
            'membership_type'   => ['required', 'string', 'exists:membership_types,code'],
            'max_borrow_limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'expiry_date'       => ['nullable', 'date', 'after:today'],
            'status'            => ['nullable', 'string', 'in:active,inactive,expired'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'email.unique'          => 'This email address has already been taken.',
            'password.confirmed'    => 'The password confirmation does not match.',
            // FIX: message updated to match the new exists: rule instead
            // of the old hardcoded in: rule.
            'membership_type.exists' => 'The selected membership type is invalid or no longer available.',
            'status.in'             => 'The selected status is invalid.',
            'expiry_date.after'     => 'The expiry date must be a date after today.',
        ];
    }
}