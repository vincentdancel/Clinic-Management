<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('employee_number', 40)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable()->unique();
            $table->string('job_title', 120);
            $table->date('hire_date');
            $table->string('employment_status', 30)->default('active')->index();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('doctor_specializations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('doctor_specialization_employee', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_specialization_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['employee_id', 'doctor_specialization_id']);
            $table->index(['doctor_specialization_id', 'is_primary'], 'doctor_spec_employee_primary_idx');
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('patient_number', 40)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name', 160)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('allergen', 160);
            $table->string('reaction', 255)->nullable();
            $table->string('severity', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'allergen']);
        });

        Schema::create('patient_medical_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition_name', 160);
            $table->text('details')->nullable();
            $table->date('diagnosed_at')->nullable();
            $table->date('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'diagnosed_at']);
        });

        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['employee_id', 'day_of_week', 'is_active']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('doctor_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('status', 30)->default('scheduled')->index();
            $table->text('reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['doctor_id', 'scheduled_at']);
            $table->index(['patient_id', 'scheduled_at']);
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('chief_complaint', 255)->nullable();
            $table->text('diagnosis')->nullable();
            $table->longText('clinical_notes')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->string('status', 30)->default('in_progress')->index();
            $table->timestamps();
        });

        Schema::create('vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('measured_at');
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('oxygen_saturation', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['consultation_id', 'measured_at']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescribed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('prescribed_at');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_category_id')->constrained()->restrictOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('name', 160)->index();
            $table->string('generic_name', 160)->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('dosage_form', 60)->nullable();
            $table->string('unit', 40);
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('selling_price', 10, 2)->default(0);
            $table->string('manufacturer', 160)->nullable();
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('dosage', 120);
            $table->string('frequency', 120);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('quantity_unit', 40);
            $table->text('instructions')->nullable();
            $table->timestamps();
            $table->index(['prescription_id', 'medicine_id']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('consultation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('status', 30)->default('unpaid')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'issued_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
            $table->index(['invoice_id', 'service_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 50)->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('method', 30);
            $table->string('reference_number', 120)->nullable();
            $table->dateTime('paid_at');
            $table->string('status', 30)->default('completed')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'paid_at']);
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 80);
            $table->date('received_at');
            $table->date('expires_at')->nullable();
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_remaining', 12, 3);
            $table->decimal('unit_cost', 10, 2);
            $table->timestamps();
            $table->unique(['medicine_id', 'batch_number']);
            $table->index(['medicine_id', 'expires_at']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->index();
            $table->decimal('quantity_change', 12, 3);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('reference_number', 120)->nullable()->index();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();
            $table->index(['medicine_id', 'occurred_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 100)->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('medicine_batches');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('services');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('medicine_categories');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('vital_signs');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('doctor_schedules');
        Schema::dropIfExists('patient_medical_histories');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('doctor_specialization_employee');
        Schema::dropIfExists('doctor_specializations');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
