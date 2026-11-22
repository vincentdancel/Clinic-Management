<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientAllergyRequest;
use App\Http\Requests\PatientIndexRequest;
use App\Http\Requests\PatientMedicalHistoryRequest;
use App\Http\Requests\PatientStoreRequest;
use App\Http\Requests\PatientUpdateRequest;
use App\Http\Resources\PatientAllergyResource;
use App\Http\Resources\PatientMedicalHistoryResource;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function index(PatientIndexRequest $request): mixed
    {
        $filters = $request->validated();
        $query = Patient::query();

        if (($filters['status'] ?? 'active') === 'archived') {
            $query->onlyTrashed();
        } elseif (($filters['status'] ?? 'active') === 'all') {
            $query->withTrashed();
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $nameParts = preg_split('/\s+/', $search, 2);
            $query->where(function ($query) use ($search, $nameParts): void {
                $query->where('patient_number', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');

                if (is_array($nameParts) && count($nameParts) === 2) {
                    $query->orWhere(function ($query) use ($nameParts): void {
                        $query->where('first_name', 'like', '%'.$nameParts[0].'%')
                            ->where('last_name', 'like', '%'.$nameParts[1].'%');
                    });
                }
            });
        }

        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        return PatientResource::collection($query->orderByDesc('created_at')->paginate(15));
    }

    public function store(PatientStoreRequest $request): JsonResponse
    {
        $patient = DB::transaction(function () use ($request): Patient {
            $patient = Patient::create([
                ...$request->validated(),
                'patient_number' => 'TEMP-'.Str::uuid(),
            ]);
            $patient->update(['patient_number' => sprintf('PAT-%s-%06d', now()->format('Y'), $patient->id)]);

            return $patient;
        });

        return (new PatientResource($patient->load(['allergies', 'medicalHistories'])))->response()->setStatusCode(201);
    }

    public function show(string $patient): PatientResource
    {
        return new PatientResource(Patient::withTrashed()->with(['allergies', 'medicalHistories'])->findOrFail($patient));
    }

    public function update(PatientUpdateRequest $request, string $patient): PatientResource
    {
        $record = Patient::query()->findOrFail($patient);
        $record->update($request->validated());

        return new PatientResource($record->load(['allergies', 'medicalHistories']));
    }

    public function destroy(string $patient): JsonResponse
    {
        Patient::query()->findOrFail($patient)->delete();

        return response()->json(['message' => 'Patient record archived.']);
    }

    public function storeAllergy(PatientAllergyRequest $request, string $patient): JsonResponse
    {
        $record = Patient::query()->findOrFail($patient);
        $allergy = $record->allergies()->create($request->validated());

        return (new PatientAllergyResource($allergy))->response()->setStatusCode(201);
    }

    public function destroyAllergy(string $patient, string $allergy): JsonResponse
    {
        $record = Patient::query()->findOrFail($patient);
        $record->allergies()->whereKey($allergy)->firstOrFail()->delete();

        return response()->json(['message' => 'Allergy record removed.']);
    }

    public function storeMedicalHistory(PatientMedicalHistoryRequest $request, string $patient): JsonResponse
    {
        $record = Patient::query()->findOrFail($patient);
        $history = $record->medicalHistories()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return (new PatientMedicalHistoryResource($history))->response()->setStatusCode(201);
    }

    public function updateMedicalHistory(PatientMedicalHistoryRequest $request, string $patient, string $history): PatientMedicalHistoryResource
    {
        $record = Patient::query()->findOrFail($patient);
        $medicalHistory = $record->medicalHistories()->whereKey($history)->firstOrFail();
        $medicalHistory->update($request->validated());

        return new PatientMedicalHistoryResource($medicalHistory);
    }

    public function destroyMedicalHistory(string $patient, string $history): JsonResponse
    {
        $record = Patient::query()->findOrFail($patient);
        $record->medicalHistories()->whereKey($history)->firstOrFail()->delete();

        return response()->json(['message' => 'Medical history record removed.']);
    }
}
