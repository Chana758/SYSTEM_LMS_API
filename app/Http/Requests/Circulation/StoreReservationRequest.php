<?php

namespace App\Http\Requests\Circulation;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $roleName = $user->role?->name;

        if (in_array($roleName, ['admin', 'librarian'])) {
            return true;
        }

        if ($roleName === 'member') {
            return Member::where('user_id', $user->id)->exists();
        }

        return false;
    }

    public function rules(): array
    {
        $isStaff = in_array($this->user()->role?->name, ['admin', 'librarian']);

        return [
            'book_id' => ['required', 'exists:books,id'],
            'member_id' => [$isStaff ? 'required' : 'nullable', 'exists:members,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'book_id.required' => __('validation.custom.reservation.book_id.required'),
            'book_id.exists' => __('validation.custom.reservation.book_id.exists'),
            'member_id.required' => __('validation.custom.reservation.member_id.required'),
            'member_id.exists' => __('validation.custom.reservation.member_id.exists'),
        ];
    }
}