<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $rolePermissions = [
            'administrator' => ['users.manage', 'roles.manage', 'permissions.manage', 'patients.view', 'patients.manage', 'employees.view', 'employees.manage', 'appointments.view', 'appointments.manage', 'clinical.view', 'clinical.manage', 'clinical.diagnose', 'billing.view', 'billing.manage', 'inventory.manage', 'reports.view', 'reports.export'],
            'doctor' => ['patients.view', 'patients.manage', 'employees.view', 'appointments.view', 'appointments.manage', 'clinical.view', 'clinical.manage', 'clinical.diagnose', 'reports.view'],
            'nurse' => ['patients.view', 'patients.manage', 'employees.view', 'appointments.view', 'appointments.manage', 'clinical.view', 'clinical.manage', 'reports.view'],
            'receptionist' => ['patients.view', 'patients.manage', 'employees.view', 'appointments.view', 'appointments.manage', 'billing.view', 'reports.view'],
            'cashier' => ['patients.view', 'employees.view', 'billing.view', 'billing.manage', 'reports.view', 'reports.export'],
            'inventory-staff' => ['employees.view', 'inventory.manage', 'reports.view', 'reports.export'],
        ];
        $roles = [];

        foreach ($rolePermissions as $slug => $permissionSlugs) {
            $name = ucwords(str_replace('-', ' ', $slug));
            $roles[$slug] = Role::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        $permissions = [];
        foreach (array_unique(array_merge(...array_values($rolePermissions))) as $slug) {
            $name = ucwords(str_replace('.', ' ', $slug));
            $permissions[$slug] = Permission::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        foreach ($rolePermissions as $roleSlug => $permissionSlugs) {
            $roles[$roleSlug]->permissions()->sync(collect($permissionSlugs)->map(fn (string $slug): int => $permissions[$slug]->id)->all());
        }

        $defaultServices = [
            ['code' => 'SRV-LAB-CBC', 'name' => 'Complete Blood Count (CBC)', 'description' => 'Standard hematology blood panel.', 'price' => 450.00, 'duration_minutes' => 15],
            ['code' => 'SRV-LAB-UA', 'name' => 'Urinalysis', 'description' => 'Routine urine examination.', 'price' => 250.00, 'duration_minutes' => 15],
            ['code' => 'SRV-DIAG-ECG', 'name' => 'Electrocardiogram (ECG)', 'description' => '12-lead resting ECG.', 'price' => 650.00, 'duration_minutes' => 20],
            ['code' => 'SRV-IMG-XRAY', 'name' => 'Chest X-Ray', 'description' => 'Standard PA chest radiograph.', 'price' => 850.00, 'duration_minutes' => 20],
            ['code' => 'SRV-PROC-DRESS', 'name' => 'Wound Dressing & Care', 'description' => 'Sterile wound cleaning and dressing.', 'price' => 350.00, 'duration_minutes' => 20],
            ['code' => 'SRV-PROC-NEB', 'name' => 'Nebulization Therapy', 'description' => 'Single-session respiratory nebulization.', 'price' => 300.00, 'duration_minutes' => 15],
        ];

        foreach ($defaultServices as $service) {
            Service::firstOrCreate(['code' => $service['code']], [...$service, 'is_active' => true]);
        }

        $adminEmail = env('CLINIC_ADMIN_EMAIL');
        $adminPassword = env('CLINIC_ADMIN_PASSWORD');

        if (is_string($adminEmail) && $adminEmail !== '' && is_string($adminPassword) && strlen($adminPassword) >= 12) {
            User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'role_id' => $roles['administrator']->id,
                    'name' => env('CLINIC_ADMIN_NAME', 'Clinic Administrator'),
                    'password' => $adminPassword,
                    'is_active' => true,
                ],
            );
        }
    }
}
