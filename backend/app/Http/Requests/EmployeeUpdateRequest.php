<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeUpdateRequest extends FormRequest
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
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'], 'first_name' => ['sometimes', 'required', 'string', 'max:100'], 'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other', 'prefer_not_to_say'])], 'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($this->employeeId())], 'employee_category' => ['sometimes', 'required', Rule::in(['doctor', 'nurse', 'receptionist', 'cashier', 'inventory_staff', 'other_personnel'])],
            'job_title' => ['sometimes', 'required', 'string', 'max:120'], 'hire_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'], 'employment_status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'on_leave', 'terminated'])], 'address' => ['nullable', 'string', 'max:5000'],
            'specialization_ids' => ['nullable', 'array'], 'specialization_ids.*' => ['integer', 'distinct', 'exists:doctor_specializations,id'], 'primary_specialization_id' => ['nullable', 'integer', 'exists:doctor_specializations,id'],
            'account_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->employeeUserId())], 'password' => ['nullable', 'confirmed', Password::defaults()], 'role_id' => ['nullable', 'integer', 'exists:roles,id'],
        ];
    }

    private function employeeId(): ?int
    {
        $employee = $this->route('employee') instanceof Employee
            ? $this->route('employee')
            : Employee::withTrashed()->find($this->route('employee'));

        return $employee?->id;
    }

    private function employeeUserId(): ?int
    {
        $employee = $this->route('employee') instanceof Employee
            ? $this->route('employee')
            : Employee::withTrashed()->find($this->route('employee'));

        return $employee?->user_id;
    }
}
