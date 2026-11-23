<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientAccountService
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    /**
     * Create a new Patient-role user account and link it exclusively to the given patient.
     *
     * @param  array{name?: string|null, email: string, password: string}  $data
     */
    public function createAccountForPatient(Patient $patient, array $data, User $actor, ?Request $request = null): User
    {
        if ($patient->trashed()) {
            throw ValidationException::withMessages([
                'patient' => ['Cannot create a portal account for an archived patient record.'],
            ]);
        }

        $email = strtolower(trim($data['email']));

        return DB::transaction(function () use ($patient, $data, $email, $actor, $request): User {
            $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);

            if ($lockedPatient->user_id !== null || User::withTrashed()->where('patient_id', $lockedPatient->id)->exists()) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient record already has a linked login account.'],
                ]);
            }

            if (User::withTrashed()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already registered to another user account.'],
                ]);
            }

            $patientRole = Role::query()->where('slug', 'patient')->firstOrFail();

            $user = User::create([
                'role_id' => $patientRole->id,
                'employee_id' => null,
                'patient_id' => $lockedPatient->id,
                'name' => trim((string) ($data['name'] ?? '')) ?: trim($lockedPatient->first_name.' '.$lockedPatient->last_name),
                'email' => $email,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $lockedPatient->update(['user_id' => $user->id]);

            $this->auditLogs->log(
                'patient.account_created',
                $lockedPatient,
                $actor,
                null,
                ['user_id' => $user->id, 'email' => $user->email],
                $request,
            );

            return $user->load('role.permissions');
        });
    }

    /**
     * Link an existing unlinked Patient-role user account to a patient record.
     */
    public function linkExistingUserToPatient(Patient $patient, User $user, User $actor, ?Request $request = null): User
    {
        if ($patient->trashed()) {
            throw ValidationException::withMessages([
                'patient' => ['Cannot link an account to an archived patient record.'],
            ]);
        }

        return DB::transaction(function () use ($patient, $user, $actor, $request): User {
            $lockedPatient = Patient::query()->lockForUpdate()->findOrFail($patient->id);
            $lockedUser = User::query()->with('role')->lockForUpdate()->findOrFail($user->id);

            if ($lockedPatient->user_id !== null || User::withTrashed()->where('patient_id', $lockedPatient->id)->exists()) {
                throw ValidationException::withMessages([
                    'patient' => ['This patient record already has a linked login account.'],
                ]);
            }

            if ($lockedUser->role?->slug !== 'patient') {
                throw ValidationException::withMessages([
                    'user_id' => ['Only user accounts with the Patient role can be linked to a patient record.'],
                ]);
            }

            if ($lockedUser->employee_id !== null || $lockedUser->employee()->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['Staff employee accounts cannot be linked as patient portal accounts.'],
                ]);
            }

            if ($lockedUser->patient_id !== null || Patient::withTrashed()->where('user_id', $lockedUser->id)->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['This user account is already linked to another patient record.'],
                ]);
            }

            $lockedUser->update(['patient_id' => $lockedPatient->id]);
            $lockedPatient->update(['user_id' => $lockedUser->id]);

            $this->auditLogs->log(
                'patient.account_linked',
                $lockedPatient,
                $actor,
                null,
                ['user_id' => $lockedUser->id, 'email' => $lockedUser->email],
                $request,
            );

            return $lockedUser->load('role.permissions');
        });
    }
}
