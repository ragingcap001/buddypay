<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class ValidateBillRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:'.implode(',', [
                'AIRTIME', 'DATA', 'ELECTRICITY', 'CABLE_TV', 'BETTING', 'BANK_TRANSFER',
            ])],
            'phone' => ['required', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/'],
        ];
    }
}
