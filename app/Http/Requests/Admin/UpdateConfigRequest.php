<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/admin/config — { group, values: { key: string|null } }
 */
class UpdateConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate is the `admin` middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $knownGroups = array_keys(config('app_config.groups', []));

        return [
            'group' => ['required', 'string', \Illuminate\Validation\Rule::in($knownGroups)],
            'values' => ['required', 'array'],
            // Keys are validated per-group in AppConfigService::set();
            // this only enforces shape.
            'values.*' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
