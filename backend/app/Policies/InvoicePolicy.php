<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isPatient()) {
            return $user->hasPermission('patient.billing.view')
                && $user->patient?->id === $invoice->patient_id;
        }

        return $user->hasPermission('billing.view');
    }
}
