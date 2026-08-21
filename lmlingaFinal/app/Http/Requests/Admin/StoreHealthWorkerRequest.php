<?php

namespace App\Http\Requests\Admin;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the slim Create Account form (authentication + role assignment).
 * Personal demographics and employment placement are completed via Edit Account Details.
 */
class StoreHealthWorkerRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'string', 'max:20'],
            'role' => ['required', 'string', Rule::in(['BHW', 'BNS', 'BSPO', 'Admin', ...StaffRole::ALL])],
            'status' => ['required', 'string', Rule::in(StaffAccountStatus::ALL)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (StaffRole::normalize($this->input('role')) === null && $this->filled('role')) {
                $validator->errors()->add('role', 'Invalid staff role.');
            }
        });
    }
}
