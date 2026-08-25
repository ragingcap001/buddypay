<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class PayBillRequest extends FormRequest
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
            // Integer kobo. Minimum ₦100 (10000), maximum ₦5,000,000.
            'amount' => ['required', 'integer', 'min:10000', 'max:500000000'],
            'phone' => ['required', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/'],
        ];
    }
}
