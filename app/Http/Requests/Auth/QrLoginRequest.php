<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class QrLoginRequest extends FormRequest
{
    /**
     * This is a public, unauthenticated endpoint — anyone holding a valid
     * QR card may attempt to log in with it, exactly like anyone who
     * knows an email/password may attempt a normal login. Authorization
     * (whether the token is actually valid/unexpired) happens inside the
     * controller against the database, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The raw token as read from the QR code by the camera/scanner.
            'qr_token' => ['required', 'string', 'max:255'],
        ];
    }
}