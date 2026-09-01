<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class FundMonnifyWalletRequest extends FormRequest
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
            // Naira, not kobo — this contract's amounts are plain integers.
            'amount' => ['required', 'integer', 'min:100', 'max:50000000'],
        ];
    }
}
