<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'appointment_number')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->string('appointment_number', 40)->nullable()->unique()->after('id');
            });
        }

        // Add non-unique index BEFORE dropping unique index so MySQL retains an index for the doctor_id FK
        if (! Schema::hasIndex('appointments', 'appointments_doctor_id_scheduled_at_index')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->index(['doctor_id', 'scheduled_at']);
            });
        }

        if (Schema::hasIndex('appointments', 'appointments_doctor_id_scheduled_at_unique')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropUnique(['doctor_id', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('appointment_histories')) {
            Schema::create('appointment_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->dateTime('from_scheduled_at')->nullable();
                $table->dateTime('to_scheduled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['appointment_id', 'created_at']);
            });
        }

        DB::table('permissions')->insertOrIgnore([
            'name' => 'View appointments',
            'slug' => 'appointments.view',
            'description' => 'View appointment schedules, calendar, and appointment history.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->insertOrIgnore([
            'name' => 'Manage appointments',
            'slug' => 'appointments.manage',
            'description' => 'Create, reschedule, cancel, and update appointment statuses.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['appointments.view', 'appointments.manage'])
            ->pluck('id', 'slug');

        $roleIds = DB::table('roles')
            ->whereIn('slug', ['administrator', 'doctor', 'nurse', 'receptionist'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', ['appointments.view', 'appointments.manage'])->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::dropIfExists('appointment_histories');

        if (! Schema::hasIndex('appointments', ['doctor_id', 'scheduled_at'], 'unique')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->unique(['doctor_id', 'scheduled_at']);
            });
        }

        if (Schema::hasIndex('appointments', ['doctor_id', 'scheduled_at'])) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropIndex(['doctor_id', 'scheduled_at']);
            });
        }

        if (Schema::hasColumn('appointments', 'appointment_number')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropUnique(['appointment_number']);
                $table->dropColumn('appointment_number');
            });
        }
    }
};
