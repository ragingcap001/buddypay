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
            // Optional bill rail selection + provider-specific details.
            'provider' => ['sometimes', 'string', 'max:40'],
            // Kuda: the purchasable bill item identifier from
            // GET /api/v1/bills/kuda/catalog (e.g. "KD-VTU-MTNNG").
            'biller' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Kuda: the biller's customer identifier (phone, meter number,
            // betting wallet/account id, ...) when it differs from `phone`.
            'customer_identifier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
