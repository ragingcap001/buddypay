<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The contract's example errors key on snake_case field names
 * (`new_pin`, `new_pin_confirm`) even though the payload is camelCase
 * (`newPin`, `newPinConfirm`) — prepareForValidation() mirrors the
 * camelCase input onto snake_case keys so the validator (and its error
 * bag) operates on those, matching the documented error shape exactly.
 */
class ResetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'old_pin' => $this->input('oldPin'),
            'new_pin' => $this->input('newPin'),
            'new_pin_confirm' => $this->input('newPinConfirm'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'old_pin' => ['required', 'string', 'regex:/^\d{4}$/'],
            'new_pin' => ['required', 'string', 'regex:/^\d{4}$/', 'different:old_pin'],
            'new_pin_confirm' => ['required', 'string', 'same:new_pin'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_pin_confirm.same' => 'The new pin confirm field must match new pin.',
        ];
    }
}
