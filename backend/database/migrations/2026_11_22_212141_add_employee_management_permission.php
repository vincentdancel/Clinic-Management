<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'View employees', 'slug' => 'employees.view', 'description' => 'View employee profiles and staff directory.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permissions')->insertOrIgnore([
            'name' => 'Manage employees', 'slug' => 'employees.manage', 'description' => 'Register, update, and activate employees and their accounts.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionIds = DB::table('permissions')->whereIn('slug', ['employees.view', 'employees.manage'])->pluck('id', 'slug');
        $roles = DB::table('roles')->whereIn('slug', ['administrator', 'doctor', 'nurse', 'receptionist', 'cashier', 'inventory-staff'])->pluck('id', 'slug');
        foreach ($roles as $roleId) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds['employees.view']]);
        }
        if (isset($roles['administrator'])) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $roles['administrator'], 'permission_id' => $permissionIds['employees.manage']]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', ['employees.view', 'employees.manage'])->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
