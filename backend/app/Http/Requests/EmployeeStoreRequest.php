<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other', 'prefer_not_to_say'])], 'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'], 'employee_category' => ['required', Rule::in(['doctor', 'nurse', 'receptionist', 'cashier', 'inventory_staff', 'other_personnel'])],
            'job_title' => ['required', 'string', 'max:120'], 'hire_date' => ['required', 'date', 'before_or_equal:today'], 'employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave', 'terminated'])], 'address' => ['nullable', 'string', 'max:5000'],
            'specialization_ids' => ['nullable', 'array'], 'specialization_ids.*' => ['integer', 'distinct', 'exists:doctor_specializations,id'], 'primary_specialization_id' => ['nullable', 'integer', 'exists:doctor_specializations,id'],
            'create_account' => ['sometimes', 'boolean'], 'account_email' => ['nullable', 'email', 'max:255', 'required_if:create_account,true', 'unique:users,email'],
            'password' => ['nullable', 'confirmed', 'required_if:create_account,true', Password::defaults()], 'role_id' => ['nullable', 'integer', 'required_if:create_account,true', 'exists:roles,id'],
        ];
    }
}
