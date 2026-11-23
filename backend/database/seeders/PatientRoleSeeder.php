<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PatientRoleSeeder extends Seeder
{
    public const PATIENT_PERMISSIONS = [
        'patient.profile.view' => 'View own patient profile.',
        'patient.profile.update' => 'Update permitted contact information.',
        'patient.appointments.view' => 'View own upcoming and past appointments.',
        'patient.appointments.request' => 'Request appointment scheduling when enabled.',
        'patient.consultations.view' => 'View own released consultation history.',
        'patient.medical_records.view' => 'View own permitted medical records.',
        'patient.prescriptions.view' => 'View own prescriptions.',
        'patient.billing.view' => 'View own invoices and payment history.',
        'patient.account.manage' => 'Change own password and account settings.',
    ];

    public function run(): void
    {
        $role = Role::firstOrCreate(
            ['slug' => 'patient'],
            [
                'name' => 'Patient',
                'description' => 'Patient portal account with read-only access to personal clinic records and permitted profile updates.',
            ],
        );

        $permissionIds = [];
        foreach (self::PATIENT_PERMISSIONS as $slug => $description) {
            $name = ucwords(str_replace(['.', '_'], ' ', $slug));
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description],
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);
    }
}
