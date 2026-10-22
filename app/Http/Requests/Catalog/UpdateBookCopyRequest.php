<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookCopyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status'         => ['sometimes', 'in:available,damaged,lost,archived'],
            'condition'      => ['sometimes', 'in:new,good,worn,damaged,lost'],
            'shelf_location' => ['nullable', 'string', 'max:50'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'reason'         => ['nullable', 'string', 'max:255'],
        ];
    }
}