<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.billing.view')
                && $user->patient?->id === $payment->invoice?->patient_id;
        }

        return $user->hasPermission('billing.view');
    }
}
