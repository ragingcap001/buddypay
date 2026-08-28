<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class AirtimePurchaseRequest extends FormRequest
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
            'network' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/'],
            'amount' => ['required', 'numeric', 'min:50', 'max:5000000'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
