<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unlike ForgotPasswordRequest, this deliberately reveals whether the
 * email is registered (via `exists`) — that is the exact behaviour the
 * mobile contract specifies for this endpoint, even though it departs
 * from the anti-enumeration stance used elsewhere in auth.
 */
class ResendEmailOtpRequest extends FormRequest
{
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
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ];
    }
}
