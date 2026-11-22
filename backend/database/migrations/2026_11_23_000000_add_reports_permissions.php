<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'name' => 'View reports and analytics',
                'slug' => 'reports.view',
                'description' => 'Access operational, clinical, financial, and inventory reports and analytics.',
            ],
            [
                'name' => 'Export reports',
                'slug' => 'reports.export',
                'description' => 'Export clinic reports to PDF and Excel formats.',
            ],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->insertOrIgnore([
                ...$perm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['reports.view', 'reports.export'])
            ->pluck('id', 'slug');

        $roles = DB::table('roles')
            ->whereIn('slug', ['administrator', 'doctor', 'nurse', 'receptionist', 'cashier', 'inventory-staff'])
            ->pluck('id', 'slug');

        foreach (['administrator', 'cashier', 'inventory-staff'] as $roleSlug) {
            if (! isset($roles[$roleSlug])) {
                continue;
            }
            foreach (['reports.view', 'reports.export'] as $permSlug) {
                if (isset($permissionIds[$permSlug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roles[$roleSlug],
                        'permission_id' => $permissionIds[$permSlug],
                    ]);
                }
            }
        }

        foreach (['doctor', 'nurse', 'receptionist'] as $roleSlug) {
            if (isset($roles[$roleSlug], $permissionIds['reports.view'])) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roles[$roleSlug],
                    'permission_id' => $permissionIds['reports.view'],
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['reports.view', 'reports.export'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};

