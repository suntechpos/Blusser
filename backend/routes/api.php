<?php

use App\Modules\Identity\Http\AuthController;
use App\Modules\Platform\Http\ClinicController as PlatformController;
use App\Modules\Platform\Http\HealthController;
use App\Modules\Tenancy\Http\ClinicController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', HealthController::class);
Route::prefix('v1')->middleware('first-party')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/ceo-recovery/{recovery}', [PlatformController::class, 'recover']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'show']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'password']);
        Route::middleware('full-identity')->group(function () {
            Route::get('auth/clinics', [AuthController::class, 'clinics']);
            Route::post('auth/active-clinic', [AuthController::class, 'select']);
            Route::get('auth/links', [ClinicController::class, 'links']);
            Route::post('auth/links/{link}/accept', [ClinicController::class, 'accept']);
            Route::post('auth/links/{link}/reject', [ClinicController::class, 'reject']);
            Route::get('platform/clinics', [PlatformController::class, 'index']);
            Route::post('platform/clinics', [PlatformController::class, 'store']);
            Route::get('platform/audit', [PlatformController::class, 'audit']);
            Route::post('platform/clinics/{tenant}/ceo-recovery', [PlatformController::class, 'recovery']);
            Route::post('platform/ceo-recoveries/{recovery}/approve', [PlatformController::class, 'approve']);
            Route::prefix('clinic')->group(function () {
                Route::get('permissions', [ClinicController::class, 'permissions']);
                Route::get('roles', [ClinicController::class, 'roles']);
                Route::get('roles/{role}', [ClinicController::class, 'roles']);
                Route::post('roles', [ClinicController::class, 'saveRole']);
                Route::put('roles/{role}', [ClinicController::class, 'saveRole']);
                Route::delete('roles/{role}', [ClinicController::class, 'deleteRole']);
                Route::get('branches', [ClinicController::class, 'branches']);
                Route::get('branches/{branch}', [ClinicController::class, 'branches']);
                Route::post('branches', [ClinicController::class, 'createBranch']);
                Route::get('employees', [ClinicController::class, 'employees']);
                Route::get('employees/{employee}', [ClinicController::class, 'employees']);
                Route::post('employees', [ClinicController::class, 'createEmployee']);
                Route::patch('employees/{employee}', [ClinicController::class, 'assignEmployee']);
                Route::delete('employees/{employee}', [ClinicController::class, 'removeEmployee']);
                Route::post('employees/{employee}/password-reset', [ClinicController::class, 'resetEmployee']);
                Route::post('identity-lookup', [ClinicController::class, 'lookup']);
                Route::post('links', [ClinicController::class, 'requestLink']);
                Route::get('audit', [ClinicController::class, 'audit']);
            });
        });
    });
});
