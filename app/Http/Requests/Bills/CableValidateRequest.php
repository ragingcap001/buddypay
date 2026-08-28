<?php

namespace App\Http\Requests\Bills;

use Illuminate\Foundation\Http\FormRequest;

class CableValidateRequest extends FormRequest
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
            'iucNumber' => ['required', 'string', 'max:30'],
            'productId' => ['required', 'string', 'max:60'],
        ];
    }
}
