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
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'reason'    => ['required', 'in:overdue,damaged,lost,other'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ];
    }
}