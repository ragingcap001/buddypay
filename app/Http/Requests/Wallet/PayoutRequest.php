<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/wallet/payout — transfer out of the wallet to a bank account.
 */
class PayoutRequest extends FormRequest
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
            'bank_code' => ['required', 'string', 'size:3'],
            'account_number' => ['required', 'string', 'size:10'],
            'account_name' => ['required', 'string', 'min:3', 'max:100'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:100'],
            'provider' => ['sometimes', 'string', Rule::in(array_keys(config('ase.payout_providers', [])))],
        ];
    }
}
