<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SetPinRequest extends FormRequest
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
            'transactionPin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'transactionPinConfirm' => ['required', 'string', 'same:transactionPin'],
        ];
    }
}
