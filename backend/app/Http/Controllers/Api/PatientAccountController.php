<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientAccountLinkRequest;
use App\Http\Requests\PatientAccountStoreRequest;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientAccountController extends Controller
{
    public function __construct(private readonly PatientAccountService $patientAccounts)
    {
    }

    public function show(Patient $patient): JsonResponse
    {
        $patient->load('user:id,name,email,is_active,role_id');

        $availableUsers = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', 'patient'))
            ->whereNull('patient_id')
            ->whereNull('employee_id')
            ->whereDoesntHave('patient')
            ->whereDoesntHave('employee')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);

        return response()->json([
            'has_portal_account' => $patient->user_id !== null,
            'portal_account' => $patient->user ? [
                'id' => $patient->user->id,
                'name' => $patient->user->name,
                'email' => $patient->user->email,
                'is_active' => (bool) $patient->user->is_active,
            ] : null,
            'available_patient_users' => $availableUsers,
        ]);
    }

    public function store(PatientAccountStoreRequest $request, Patient $patient): JsonResponse
    {
        $user = $this->patientAccounts->createAccountForPatient(
            $patient,
            $request->validated(),
            $request->user(),
            $request,
        );

        return response()->json([
            'message' => 'Patient portal account created and linked.',
            'portal_account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'patient_id' => $user->patient_id,
            ],
        ], 201);
    }

    public function link(PatientAccountLinkRequest $request, Patient $patient): JsonResponse
    {
        $userToLink = User::query()->findOrFail($request->validated('user_id'));

        $user = $this->patientAccounts->linkExistingUserToPatient(
            $patient,
            $userToLink,
            $request->user(),
            $request,
        );

        return response()->json([
            'message' => 'Existing user account linked to patient record.',
            'portal_account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'patient_id' => $user->patient_id,
            ],
        ]);
    }
}
