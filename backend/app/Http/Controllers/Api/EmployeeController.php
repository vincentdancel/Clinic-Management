<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeIndexRequest;
use App\Http\Requests\EmployeeStoreRequest;
use App\Http\Requests\EmployeeUpdateRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index(EmployeeIndexRequest $request): mixed
    {
        $filters = $request->validated();
        $query = Employee::query()->with(['department', 'doctorSpecializations', 'user.role']);

        if (($filters['status'] ?? 'active') === 'all') {
            $query->withTrashed();
        } elseif (in_array($filters['status'] ?? 'active', ['inactive', 'on_leave', 'terminated'], true)) {
            $query->where('employment_status', $filters['status']);
        } else {
            $query->where('employment_status', 'active');
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('employee_number', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['position'])) {
            $query->where('job_title', $filters['position']);
        }

        return EmployeeResource::collection($query->orderBy('last_name')->orderBy('first_name')->paginate(15));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'specializations' => DB::table('doctor_specializations')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(EmployeeStoreRequest $request): JsonResponse
    {
        $employee = DB::transaction(function () use ($request): Employee {
            $data = $request->validated();
            $employee = Employee::create([
                ...collect($data)->except(['specialization_ids', 'primary_specialization_id', 'create_account', 'account_email', 'password', 'password_confirmation', 'role_id'])->all(),
                'employee_number' => 'T-'.Str::random(16),
            ]);
            $employee->update(['employee_number' => sprintf('EMP-%s-%06d', now()->format('Y'), $employee->id)]);
            $this->syncSpecializations($employee, $data);

            if (($data['create_account'] ?? false) === true) {
                $user = User::create([
                    'role_id' => $data['role_id'],
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'email' => $data['account_email'],
                    'password' => $data['password'],
                    'is_active' => true,
                ]);
                $employee->user()->associate($user)->save();
            }

            return $employee;
        });

        return (new EmployeeResource($employee->load(['department', 'doctorSpecializations', 'user.role'])))->response()->setStatusCode(201);
    }

    public function show(string $employee): EmployeeResource
    {
        return new EmployeeResource(Employee::withTrashed()->with(['department', 'doctorSpecializations', 'user.role'])->findOrFail($employee));
    }

    public function update(EmployeeUpdateRequest $request, string $employee): EmployeeResource
    {
        $record = Employee::query()->findOrFail($employee);
        $data = $request->validated();

        DB::transaction(function () use ($record, $data): void {
            $record->update(collect($data)->except(['specialization_ids', 'primary_specialization_id', 'account_email', 'password', 'password_confirmation', 'role_id'])->all());
            if (array_key_exists('specialization_ids', $data) || array_key_exists('primary_specialization_id', $data)) {
                $this->syncSpecializations($record, $data);
            }

            if (! empty($data['account_email']) || ! empty($data['password']) || ! empty($data['role_id'])) {
                $user = $record->user;
                if (! $user) {
                    abort_unless(! empty($data['account_email']) && ! empty($data['password']) && ! empty($data['role_id']), 422, 'Email, password, and role are required to create an account.');
                    $user = new User(['name' => trim($record->first_name.' '.$record->last_name), 'is_active' => true]);
                    $user->email = $data['account_email'];
                    $user->password = $data['password'];
                    $user->role_id = $data['role_id'];
                    $user->save();
                    $record->user()->associate($user)->save();
                } else {
                    $user->fill(array_filter([
                        'email' => $data['account_email'] ?? null,
                        'password' => $data['password'] ?? null,
                        'role_id' => $data['role_id'] ?? null,
                    ], fn ($value): bool => $value !== null));
                    $user->name = trim($record->first_name.' '.$record->last_name);
                    $user->save();
                }
            }
        });

        return new EmployeeResource($record->fresh()->load(['department', 'doctorSpecializations', 'user.role']));
    }

    public function destroy(string $employee): JsonResponse
    {
        $record = Employee::query()->with('user')->findOrFail($employee);
        DB::transaction(function () use ($record): void {
            if ($record->user) {
                $record->user->update(['is_active' => false]);
                $record->user->tokens()->delete();
            }
            $record->delete();
        });

        return response()->json(['message' => 'Employee record archived.']);
    }

    /** @param array<string, mixed> $data */
    private function syncSpecializations(Employee $employee, array $data): void
    {
        $specializationIds = $data['specialization_ids'] ?? [];
        $primaryId = $data['primary_specialization_id'] ?? null;
        abort_if($primaryId !== null && ! in_array($primaryId, $specializationIds, false), 422, 'The primary specialization must be selected.');

        $employee->doctorSpecializations()->sync(collect($specializationIds)->mapWithKeys(fn ($id): array => [
            $id => ['is_primary' => (int) $id === (int) $primaryId],
        ])->all());
    }
}
