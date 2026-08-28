<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class BettingPurchaseRequest extends FormRequest
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
            'customerId' => ['required', 'string', 'max:60'],
            'productId' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:50', 'max:5000000'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
