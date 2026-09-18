<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class NotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by role:admin,librarian middleware in api.php
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:1000'],
            'type' => ['required', 'string', 'in:overdue,reservation,fine,announcement,system'],
            'broadcast' => ['required', 'boolean'],
            // required only when NOT broadcasting to everyone
            'user_id' => ['required_if:broadcast,false', 'nullable', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('validation.custom.notification.title.required'),
            'message.required' => __('validation.custom.notification.message.required'),
            'user_id.required_if' => __('validation.custom.notification.user_id.required_if'),
            'user_id.exists' => __('validation.custom.notification.user_id.exists'),
        ];
    }
}