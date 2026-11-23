<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.appointments.view')
                && $user->patient?->id === $appointment->patient_id;
        }

        return $user->hasPermission('appointments.view');
    }
}
