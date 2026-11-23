<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function view(User $user, Patient $patient): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.profile.view')
                && $user->patient?->id === $patient->id;
        }

        return $user->hasPermission('patients.view');
    }

    public function update(User $user, Patient $patient): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.profile.update')
                && $user->patient?->id === $patient->id;
        }

        return $user->hasPermission('patients.manage');
    }
}
