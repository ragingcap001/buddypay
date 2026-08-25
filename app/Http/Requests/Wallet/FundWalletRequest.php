<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class FundWalletRequest extends FormRequest
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
            // Amounts are integer kobo. Minimum ₦1 (100), maximum ₦50,000,000.
            'amount' => ['required', 'integer', 'min:100', 'max:5000000000'],
            'method' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
