<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
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
