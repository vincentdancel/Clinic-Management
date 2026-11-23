<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });

        DB::table('permissions')->insertOrIgnore([
            'name' => 'Manage clinic settings',
            'slug' => 'settings.manage',
            'description' => 'Update clinic profile, operating hours, billing, and notification preferences.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'settings.manage')->value('id');
        $adminRoleId = DB::table('roles')->where('slug', 'administrator')->value('id');

        if ($permissionId && $adminRoleId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'settings.manage')->value('id');
        if ($permissionId) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('clinic_settings');
    }
};
