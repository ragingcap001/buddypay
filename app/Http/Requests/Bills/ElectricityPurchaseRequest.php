<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class ElectricityPurchaseRequest extends FormRequest
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
            'meterNumber' => ['required', 'string', 'max:30'],
            'productId' => ['required', 'string', 'max:60'],
            'type' => ['required', 'string', 'in:prepaid,postpaid'],
            'amount' => ['required', 'numeric', 'min:50', 'max:5000000'],
            'pin' => ['required', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
