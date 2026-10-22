<?php

namespace App\Http\Requests\Circulation;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule; // <-- was missing: Rule::in() crashed with "Class Rule not found"

class StoreReservationRequest extends FormRequest
{
    protected function isStaff(): bool
    {
        return in_array($this->user()?->role?->name, ['admin', 'librarian'], true);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        if ($this->isStaff()) {
            return true;
        }

        // A normal member needs a member profile.
        return $user->role?->name === 'member'
            && Member::where('user_id', $user->id)->exists();
    }

    public function rules(): array
    {
        $ownMemberId = Member::where('user_id', $this->user()?->id)->value('id');

        return [
            'book_id' => ['required', 'integer', 'exists:books,id'],

            // Staff pick a member; a member can only reserve for themselves.
            'member_id' => $this->isStaff()
                ? ['required', 'integer', 'exists:members,id']
                : ['nullable', 'integer', Rule::in([$ownMemberId])],
        ];
    }

    public function attributes(): array
    {
        return [
            'book_id'   => 'book',
            'member_id' => 'member',
        ];
    }
}