<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Retrieve the user ID associated with the member to ignore in the unique check
        $userId = $this->route('member')?->user_id;

        return [
            'name'              => ['sometimes', 'string', 'max:100'],
            'email'             => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone'             => ['nullable', 'string', 'max:20'],
            'identity_card_no'  => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:20'],
            // FIX: was 'in:student,teacher,external' — now validated
            // against the membership_types master table instead, same
            // reasoning as StoreMemberRequest above.
            'membership_type'   => ['sometimes', 'string', 'exists:membership_types,code'],
            'max_borrow_limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'expiry_date'       => ['nullable', 'date'],
            'status'            => ['sometimes', 'string', 'in:active,inactive,expired'],
            'remarks'           => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.unique'           => 'This email address is already associated with another member.',
            // FIX: message updated to match the new exists: rule.
            'membership_type.exists' => 'The selected membership type is invalid or no longer available.',
            'status.in'              => 'The selected status is invalid.',
        ];
    }
}