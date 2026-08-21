<?php

namespace App\Http\Requests\Admin;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHealthWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('hw_end_appointment') === '') {
            $this->merge(['hw_end_appointment' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'sex' => ['required', 'string', Rule::in(['Male', 'Female'])],
            'hw_first_name' => ['required', 'string', 'max:100'],
            'hw_last_name' => ['required', 'string', 'max:100'],
            'hw_middle_name' => ['required', 'string', 'max:100'],
            'hw_suffix' => ['required', 'string', 'max:20'],
            'hw_dob' => ['required', 'date'],
            'hw_civil_status' => ['required', 'string', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])],
            'hw_nationality' => ['required', 'string', Rule::in(['Filipino', 'Other'])],
            'hw_mobile' => ['required', 'string', 'max:20'],
            'hw_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore(ctype_digit((string) $userId) ? (int) $userId : null),
            ],
            'hw_house_no' => ['required', 'string', 'max:20'],
            'hw_street' => ['required', 'string', 'max:150'],
            'hw_purok_zone' => ['required', 'string', Rule::in(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'])],
            'hw_barangay' => ['required', 'string', 'max:100'],
            'hw_municipality' => ['required', 'string', 'max:100'],
            'hw_province' => ['required', 'string', 'max:100'],
            'hw_zip' => ['required', 'string', 'max:10'],
            'hw_role' => ['required', 'string', Rule::in(['BHW', 'BNS', 'BSPO', 'Admin', ...StaffRole::ALL])],
            'hw_assigned_barangay' => ['required', 'string', 'max:100'],
            'hw_assigned_zone' => ['required', 'string', Rule::in(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5'])],
            'hw_date_appointed' => ['required', 'date'],
            'hw_end_appointment' => ['nullable', 'date'],
            'hw_username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('users', 'username')->ignore(ctype_digit((string) $userId) ? (int) $userId : null),
            ],
            'hw_status' => ['required', 'string', Rule::in(StaffAccountStatus::ALL)],
            'hw_password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'hw_password_confirmation' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = StaffRole::normalize($this->input('hw_role'));
            if ($role === null && $this->filled('hw_role')) {
                $validator->errors()->add('hw_role', 'Invalid staff role.');
            }

            $appointed = $this->input('hw_date_appointed');
            $ended = $this->input('hw_end_appointment');
            if (is_string($appointed) && is_string($ended) && $appointed !== '' && $ended !== '') {
                if (strcmp($ended, $appointed) < 0) {
                    $validator->errors()->add(
                        'hw_end_appointment',
                        'End of appointment must be on or after the date appointed.'
                    );
                }
            }
        });
    }
}
