<?php

namespace App\Http\Requests\GiftCards;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseGiftCardRequest extends FormRequest
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
            'gift_card_product_id' => ['required', 'integer', 'min:1'],
            'denomination' => ['required', 'numeric', 'min:0.01'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
