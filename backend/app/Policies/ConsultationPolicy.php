<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    public function view(User $user, Consultation $consultation): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.consultations.view')
                && $user->patient?->id === $consultation->patient_id
                && $consultation->is_released_to_patient === true
                && $consultation->status === Consultation::STATUS_COMPLETED;
        }

        return $user->hasPermission('clinical.view');
    }

    public function update(User $user, Consultation $consultation): bool
    {
        if ($user->isPatient()) {
            return false;
        }

        return $user->hasPermission('clinical.manage');
    }
}
