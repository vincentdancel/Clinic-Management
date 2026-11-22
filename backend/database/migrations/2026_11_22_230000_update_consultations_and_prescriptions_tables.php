<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->string('consultation_number', 40)->nullable()->unique()->after('id');
            $table->foreignId('patient_id')->nullable()->after('consultation_number')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->after('patient_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->after('doctor_id')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('appointment_id')->nullable()->change();
            $table->text('symptoms')->nullable()->after('chief_complaint');
            $table->text('treatment_plan')->nullable()->after('diagnosis');
            $table->index(['patient_id', 'started_at']);
            $table->index(['doctor_id', 'started_at']);
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });

        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->dropForeign(['medicine_id']);
        });

        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('medicine_id')->nullable()->change();
            $table->string('medicine_name', 160)->nullable()->after('medicine_id');
            $table->string('duration', 80)->nullable()->after('frequency');
            $table->string('quantity_unit', 40)->nullable()->default('units')->change();
        });

        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->foreign('medicine_id')->references('id')->on('medicines')->nullOnDelete();
        });

        $permissions = [
            [
                'name' => 'View clinical records',
                'slug' => 'clinical.view',
                'description' => 'View patient consultations, vital signs, and medical records.',
            ],
            [
                'name' => 'Manage clinical intake and vitals',
                'slug' => 'clinical.manage',
                'description' => 'Create consultations and record vital signs and clinical intake notes.',
            ],
            [
                'name' => 'Diagnose and prescribe',
                'slug' => 'clinical.diagnose',
                'description' => 'Record confidential diagnoses, treatment plans, and prescriptions.',
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
            ->whereIn('slug', ['clinical.view', 'clinical.manage', 'clinical.diagnose'])
            ->pluck('id', 'slug');

        $roles = DB::table('roles')
            ->whereIn('slug', ['administrator', 'doctor', 'nurse'])
            ->pluck('id', 'slug');

        foreach (['administrator', 'doctor'] as $roleSlug) {
            if (! isset($roles[$roleSlug])) {
                continue;
            }
            foreach (['clinical.view', 'clinical.manage', 'clinical.diagnose'] as $permSlug) {
                if (isset($permissionIds[$permSlug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roles[$roleSlug],
                        'permission_id' => $permissionIds[$permSlug],
                    ]);
                }
            }
        }

        if (isset($roles['nurse'])) {
            foreach (['clinical.view', 'clinical.manage'] as $permSlug) {
                if (isset($permissionIds[$permSlug])) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roles['nurse'],
                        'permission_id' => $permissionIds[$permSlug],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', ['clinical.view', 'clinical.diagnose'])->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->dropForeign(['medicine_id']);
            $table->dropColumn(['medicine_name', 'duration']);
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['doctor_id']);
            $table->dropForeign(['recorded_by']);
            $table->dropForeign(['appointment_id']);
            $table->dropIndex(['patient_id', 'started_at']);
            $table->dropIndex(['doctor_id', 'started_at']);
            $table->dropColumn(['consultation_number', 'patient_id', 'doctor_id', 'recorded_by', 'symptoms', 'treatment_plan']);
        });
    }
};

