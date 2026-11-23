<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'patient_id')) {
                $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('employee_id');
            $table->unique('patient_id');
        });

        Schema::table('patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('patients', 'status')) {
                $table->string('status', 30)->default('active')->index();
            }
        });

        Schema::table('consultations', function (Blueprint $table): void {
            if (! Schema::hasColumn('consultations', 'is_released_to_patient')) {
                $table->boolean('is_released_to_patient')->default(false)->index();
            }

            if (! Schema::hasColumn('consultations', 'released_to_patient_at')) {
                $table->timestamp('released_to_patient_at')->nullable();
            }

            if (! Schema::hasColumn('consultations', 'released_to_patient_by')) {
                $table->foreignId('released_to_patient_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        // Backfill existing employee and patient links onto users without modifying existing records.
        $employeeLinks = DB::table('employees')
            ->whereNotNull('user_id')
            ->pluck('id', 'user_id');

        foreach ($employeeLinks as $userId => $employeeId) {
            DB::table('users')
                ->where('id', $userId)
                ->whereNull('employee_id')
                ->update(['employee_id' => $employeeId]);
        }

        $patientLinks = DB::table('patients')
            ->whereNotNull('user_id')
            ->pluck('id', 'user_id');

        foreach ($patientLinks as $userId => $patientId) {
            DB::table('users')
                ->where('id', $userId)
                ->whereNull('patient_id')
                ->update(['patient_id' => $patientId]);
        }

        DB::table('patients')
            ->whereNotNull('deleted_at')
            ->update(['status' => 'archived']);

        // Seed Patient role and patient-scoped permissions idempotently.
        $now = now();
        DB::table('roles')->insertOrIgnore([
            'name' => 'Patient',
            'slug' => 'patient',
            'description' => 'Patient portal account with read-only access to personal clinic records and permitted profile updates.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $patientRoleId = DB::table('roles')->where('slug', 'patient')->value('id');

        $permissions = [
            ['name' => 'Patient Profile View', 'slug' => 'patient.profile.view', 'description' => 'View own patient profile.'],
            ['name' => 'Patient Profile Update', 'slug' => 'patient.profile.update', 'description' => 'Update permitted contact information.'],
            ['name' => 'Patient Appointments View', 'slug' => 'patient.appointments.view', 'description' => 'View own upcoming and past appointments.'],
            ['name' => 'Patient Appointments Request', 'slug' => 'patient.appointments.request', 'description' => 'Request appointment scheduling when enabled.'],
            ['name' => 'Patient Consultations View', 'slug' => 'patient.consultations.view', 'description' => 'View own released consultation history.'],
            ['name' => 'Patient Medical Records View', 'slug' => 'patient.medical_records.view', 'description' => 'View own permitted medical records.'],
            ['name' => 'Patient Prescriptions View', 'slug' => 'patient.prescriptions.view', 'description' => 'View own prescriptions.'],
            ['name' => 'Patient Billing View', 'slug' => 'patient.billing.view', 'description' => 'View own invoices and payment history.'],
            ['name' => 'Patient Account Manage', 'slug' => 'patient.account.manage', 'description' => 'Change own password and account settings.'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                ...$permission,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($patientRoleId) {
            $permissionIds = DB::table('permissions')
                ->whereIn('slug', array_column($permissions, 'slug'))
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $patientRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $patientRoleId = DB::table('roles')->where('slug', 'patient')->value('id');
        $permissionSlugs = [
            'patient.profile.view',
            'patient.profile.update',
            'patient.appointments.view',
            'patient.appointments.request',
            'patient.consultations.view',
            'patient.medical_records.view',
            'patient.prescriptions.view',
            'patient.billing.view',
            'patient.account.manage',
        ];
        $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');

        if ($patientRoleId) {
            DB::table('role_permission')->where('role_id', $patientRoleId)->delete();
            DB::table('roles')->where('id', $patientRoleId)->delete();
        }

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::table('consultations', function (Blueprint $table): void {
            if (Schema::hasColumn('consultations', 'released_to_patient_by')) {
                $table->dropConstrainedForeignId('released_to_patient_by');
            }
            if (Schema::hasColumn('consultations', 'released_to_patient_at')) {
                $table->dropColumn('released_to_patient_at');
            }
            if (Schema::hasColumn('consultations', 'is_released_to_patient')) {
                $table->dropColumn('is_released_to_patient');
            }
        });

        Schema::table('patients', function (Blueprint $table): void {
            if (Schema::hasColumn('patients', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'patient_id')) {
                $table->dropUnique(['patient_id']);
                $table->dropConstrainedForeignId('patient_id');
            }
            if (Schema::hasColumn('users', 'employee_id')) {
                $table->dropUnique(['employee_id']);
                $table->dropConstrainedForeignId('employee_id');
            }
        });
    }
};
