<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $userId = $this->user('sanctum')?->id;

        return [
            'firstName' => ['sometimes', 'string', 'max:60'],
            'lastName' => ['sometimes', 'string', 'max:60'],
            'gender' => ['sometimes', 'string', 'in:male,female'],
            'phone' => [
                'sometimes',
                'string',
                'regex:/^(\+234|0)[789][01]\d{8}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
        ];
    }
}
