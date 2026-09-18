<?php

namespace App\Http\Requests\Circulation;

use Illuminate\Foundation\Http\FormRequest;

class ReturnBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'condition' => ['required', 'string', 'in:good,damaged,lost'],
            // damage_fee / lost_fee arrive as raw Riel amounts from the
            // frontend — CurrencyHelper::khrToUsd() converts server-side.
            'damage_fee' => ['required_if:condition,damaged', 'nullable', 'numeric', 'min:0'],
            'lost_fee' => ['required_if:condition,lost', 'nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'condition.required' => __('validation.custom.return.condition.required'),
            'condition.in' => __('validation.custom.return.condition.in'),
            'damage_fee.required_if' => __('validation.custom.return.damage_fee.required_if'),
            'lost_fee.required_if' => __('validation.custom.return.lost_fee.required_if'),
        ];
    }
}