<?php

namespace App\Http\Requests\Fine;

use Illuminate\Foundation\Http\FormRequest;

class StoreFineRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /**
     * FIX: added max:500 — a single book fine should never realistically
     * exceed $500. Without this cap, a librarian typo (e.g. typing "5000"
     * instead of "50.00") gets silently accepted and stored as-is, since
     * the only prior rule was min:0.01 (any positive number passes).
     * This is a hard business-rule ceiling enforced server-side — the
     * frontend check (fineValidation.js) is UX-only and can be bypassed
     * by anyone hitting the API directly, so this is the real guard.
     */
    public function rules(): array
    {
        return [
            'borrow_id' => ['required', 'exists:borrow_transactions,id'],
            'amount'    => ['required', 'numeric', 'min:0.01', 'max:500'],
            'reason'    => ['required', 'in:overdue,damaged,lost,other'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     *  NEW — custom message so librarian sees a clear explanation
     * instead of Laravel's generic "amount must not be greater than 500".
     */
    public function messages(): array
    {
        return [
            'amount.max' => 'Fine amount cannot exceed $500. If this book genuinely costs more to replace, contact an administrator to record it manually.',
        ];
    }
}