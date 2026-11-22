<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
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
            'administrator' => ['users.manage', 'roles.manage', 'permissions.manage', 'patients.view', 'appointments.manage', 'clinical.manage', 'billing.manage', 'inventory.manage'],
            'doctor' => ['patients.view', 'appointments.manage', 'clinical.manage'],
            'nurse' => ['patients.view', 'appointments.manage', 'clinical.manage'],
            'receptionist' => ['patients.view', 'appointments.manage'],
            'cashier' => ['patients.view', 'billing.manage'],
            'inventory-staff' => ['inventory.manage'],
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
