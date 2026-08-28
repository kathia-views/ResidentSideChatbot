<?php

namespace App\Http\Requests\Admin;

use App\Support\ResidentAccountUiCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResidentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['first_name', 'middle_name', 'last_name', 'email'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }

        if ($this->input('middle_name') === '') {
            $this->merge(['middle_name' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = ResidentAccountUiCatalog::findModel((string) $this->route('id'));

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'zone' => ['required', 'string', Rule::in(ResidentAccountUiCatalog::ALLOWED_ZONES)],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('resident_accounts', 'email')->ignore($account?->account_id, 'account_id'),
            ],
        ];
    }
}
