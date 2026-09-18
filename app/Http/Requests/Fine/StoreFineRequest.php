<?php

namespace App\Http\Requests\Fine;

use Illuminate\Foundation\Http\FormRequest;

class StoreFineRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
     *  Custom message so librarian sees a clear explanation
     * instead of Laravel's generic "amount must not be greater than 500".
     */
    public function messages(): array
    {
        return [
            'amount.max' => __('validation.custom.fine.amount.max'),
        ];
    }
}