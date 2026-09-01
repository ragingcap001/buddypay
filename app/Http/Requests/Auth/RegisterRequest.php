<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/', 'unique:users,phone'],
            'gender' => ['required', 'string', 'in:male,female'],
            'password' => ['required', 'string', 'min:8'],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Nigerian phone number (e.g. 08031234567 or +2348031234567).',
            'passwordConfirmation.same' => 'The password confirmation does not match.',
        ];
    }
}
