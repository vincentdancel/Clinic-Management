<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'clinic-management-api',
    ]);
});

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('admin')->group(function (): void {
    Route::middleware('permission:users.manage')->group(function (): void {
        Route::apiResource('users', UserManagementController::class)->only(['index', 'store', 'update']);
        Route::patch('/users/{user}/active', [UserManagementController::class, 'setActive']);
    });
    Route::middleware('permission:roles.manage')->apiResource('roles', RoleController::class)->except(['show']);
    Route::middleware('permission:permissions.manage')->apiResource('permissions', PermissionController::class)->except(['show']);
});

Route::middleware(['auth:sanctum', 'active'])->get('/user', [AuthController::class, 'me']);
Route::middleware(['auth:sanctum', 'active'])->get('/dashboard', DashboardController::class);
Route::middleware(['auth:sanctum', 'active'])->get('/search', SearchController::class);

Route::middleware(['auth:sanctum', 'active'])->prefix('patients')->group(function (): void {
    Route::middleware('permission:patients.view')->group(function (): void {
        Route::get('/', [PatientController::class, 'index']);
        Route::get('/{patient}', [PatientController::class, 'show'])->whereNumber('patient');
    });

    Route::middleware('permission:patients.manage')->group(function (): void {
        Route::post('/', [PatientController::class, 'store']);
        Route::put('/{patient}', [PatientController::class, 'update'])->whereNumber('patient');
        Route::patch('/{patient}', [PatientController::class, 'update'])->whereNumber('patient');
        Route::delete('/{patient}', [PatientController::class, 'destroy'])->whereNumber('patient');
        Route::post('/{patient}/allergies', [PatientController::class, 'storeAllergy'])->whereNumber('patient');
        Route::delete('/{patient}/allergies/{allergy}', [PatientController::class, 'destroyAllergy'])->whereNumber('patient')->whereNumber('allergy');
        Route::post('/{patient}/medical-history', [PatientController::class, 'storeMedicalHistory'])->whereNumber('patient');
        Route::put('/{patient}/medical-history/{history}', [PatientController::class, 'updateMedicalHistory'])->whereNumber('patient')->whereNumber('history');
        Route::delete('/{patient}/medical-history/{history}', [PatientController::class, 'destroyMedicalHistory'])->whereNumber('patient')->whereNumber('history');
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('employees')->group(function (): void {
    Route::middleware('permission:employees.view')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::get('/options', [EmployeeController::class, 'options']);
        Route::get('/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee');
    });

    Route::middleware('permission:employees.manage')->group(function (): void {
        Route::post('/', [EmployeeController::class, 'store']);
        Route::put('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee');
        Route::patch('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee');
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('appointments')->group(function (): void {
    Route::middleware('permission:appointments.view')->group(function (): void {
        Route::get('/', [AppointmentController::class, 'index']);
        Route::get('/options', [AppointmentController::class, 'options']);
        Route::get('/available-slots', [AppointmentController::class, 'availableSlots']);
        Route::get('/doctor-schedules', [AppointmentController::class, 'doctorSchedules']);
        Route::get('/{appointment}', [AppointmentController::class, 'show'])->whereNumber('appointment');
        Route::get('/{appointment}/history', [AppointmentController::class, 'history'])->whereNumber('appointment');
    });

    Route::middleware('permission:appointments.manage')->group(function (): void {
        Route::post('/', [AppointmentController::class, 'store']);
        Route::put('/{appointment}', [AppointmentController::class, 'update'])->whereNumber('appointment');
        Route::patch('/{appointment}', [AppointmentController::class, 'update'])->whereNumber('appointment');
        Route::patch('/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->whereNumber('appointment');
        Route::patch('/{appointment}/status', [AppointmentController::class, 'updateStatus'])->whereNumber('appointment');
        Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel'])->whereNumber('appointment');
        Route::patch('/{appointment}/cancel', [AppointmentController::class, 'cancel'])->whereNumber('appointment');
        Route::post('/doctor-schedules', [AppointmentController::class, 'storeDoctorSchedule']);
        Route::delete('/doctor-schedules/{schedule}', [AppointmentController::class, 'destroyDoctorSchedule'])->whereNumber('schedule');
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('consultations')->group(function (): void {
    Route::middleware('permission:clinical.view')->group(function (): void {
        Route::get('/', [ConsultationController::class, 'index']);
        Route::get('/options', [ConsultationController::class, 'options']);
        Route::get('/patients/{patient}/history', [ConsultationController::class, 'patientHistory'])->whereNumber('patient');
        Route::get('/{consultation}', [ConsultationController::class, 'show'])->whereNumber('consultation');
        Route::get('/{consultation}/audit-logs', [ConsultationController::class, 'auditLogs'])->whereNumber('consultation');
    });

    Route::middleware('permission:clinical.manage')->group(function (): void {
        Route::post('/', [ConsultationController::class, 'store']);
        Route::put('/{consultation}', [ConsultationController::class, 'update'])->whereNumber('consultation');
        Route::patch('/{consultation}', [ConsultationController::class, 'update'])->whereNumber('consultation');
        Route::post('/{consultation}/vital-signs', [ConsultationController::class, 'storeVitalSigns'])->whereNumber('consultation');
    });

    Route::middleware('permission:clinical.diagnose')->group(function (): void {
        Route::post('/{consultation}/prescriptions', [ConsultationController::class, 'storePrescription'])->whereNumber('consultation');
        Route::patch('/{consultation}/prescriptions/{prescription}/status', [ConsultationController::class, 'updatePrescriptionStatus'])->whereNumber('consultation')->whereNumber('prescription');
        Route::post('/{consultation}/complete', [ConsultationController::class, 'complete'])->whereNumber('consultation');
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('invoices')->group(function (): void {
    Route::middleware('permission:billing.view')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/options', [InvoiceController::class, 'options']);
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');
    });

    Route::middleware('permission:billing.manage')->group(function (): void {
        Route::post('/', [InvoiceController::class, 'store']);
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice');
        Route::patch('/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice');
        Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('invoice');
    });
});

Route::middleware(['auth:sanctum', 'active'])->prefix('payments')->group(function (): void {
    Route::middleware('permission:billing.view')->group(function (): void {
        Route::get('/', [PaymentController::class, 'index']);
        Route::get('/{payment}', [PaymentController::class, 'show'])->whereNumber('payment');
    });

    Route::middleware('permission:billing.manage')->group(function (): void {
        Route::post('/', [PaymentController::class, 'store']);
    });
});

Route::middleware(['auth:sanctum', 'active', 'permission:inventory.manage'])->prefix('inventory')->group(function (): void {
    Route::get('/options', [InventoryController::class, 'options']);
    Route::get('/categories', [InventoryController::class, 'categoriesIndex']);
    Route::post('/categories', [InventoryController::class, 'storeCategory']);
    Route::put('/categories/{category}', [InventoryController::class, 'updateCategory'])->whereNumber('category');
    Route::patch('/categories/{category}', [InventoryController::class, 'updateCategory'])->whereNumber('category');
    Route::delete('/categories/{category}', [InventoryController::class, 'destroyCategory'])->whereNumber('category');
    Route::get('/medicines', [InventoryController::class, 'index']);
    Route::post('/medicines', [InventoryController::class, 'store']);
    Route::get('/medicines/{medicine}', [InventoryController::class, 'show'])->whereNumber('medicine');
    Route::put('/medicines/{medicine}', [InventoryController::class, 'update'])->whereNumber('medicine');
    Route::patch('/medicines/{medicine}', [InventoryController::class, 'update'])->whereNumber('medicine');
    Route::delete('/medicines/{medicine}', [InventoryController::class, 'destroy'])->whereNumber('medicine');
    Route::post('/medicines/{medicine}/receive', [InventoryController::class, 'receive'])->whereNumber('medicine');
    Route::post('/medicines/{medicine}/adjust', [InventoryController::class, 'adjust'])->whereNumber('medicine');
    Route::get('/transactions', [InventoryController::class, 'transactions']);
});

Route::middleware(['auth:sanctum', 'active', 'permission:reports.view'])->prefix('reports')->group(function (): void {
    Route::get('/options', [ReportController::class, 'options']);
    Route::get('/{type}/export', [ReportController::class, 'export']);
    Route::get('/{type}', [ReportController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'active'])->prefix('settings')->group(function (): void {
    Route::get('/', [SettingController::class, 'index']);
    Route::put('/', [SettingController::class, 'update'])->middleware('permission:settings.manage');
});
