<?php

use App\Modules\Appointments\Http\AppointmentController;
use App\Modules\Cashier\Http\UnlockController;
use App\Modules\Identity\Http\AuthController;
use App\Modules\Patients\Http\PatientController;
use App\Modules\Platform\Http\ClinicController as PlatformController;
use App\Modules\Platform\Http\HealthController;
use App\Modules\Queues\Http\DoctorWorkspaceController;
use App\Modules\Queues\Http\QueueController;
use App\Modules\Queues\Http\VisitClinicalNoteController;
use App\Modules\Queues\Http\VisitRecommendationController;
use App\Modules\Queues\Http\VisitReferralController;
use App\Modules\Queues\Http\VisitPrescriptionController;
use App\Modules\Queues\Http\VisitDiagnosisController;
use App\Modules\Scheduling\Http\EmployeeWorkController;
use App\Modules\Scheduling\Http\SchedulingController;
use App\Modules\Services\Http\ServiceCatalogController;
use App\Modules\Tenancy\Http\ClinicController;
use App\Modules\Tenancy\Http\ContextController;
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
                Route::get('employee-work-schedules/{employee}', [EmployeeWorkController::class, 'show'])->whereUuid('employee');
                Route::put('employee-work-schedules/{employee}', [EmployeeWorkController::class, 'save'])->whereUuid('employee');
                Route::post('cashier/unlock', UnlockController::class);
                foreach (['doctors', 'rooms', 'schedules', 'schedule-exceptions', 'specialties'] as $kind) {
                    Route::get($kind, [SchedulingController::class, 'index'])->defaults('resourceKind', $kind);
                    Route::post($kind, [SchedulingController::class, 'store'])->defaults('resourceKind', $kind);
                    if ($kind !== 'specialties') {
                        Route::get($kind.'/{id}', [SchedulingController::class, 'show'])->whereUuid('id')->defaults('resourceKind', $kind);
                    }
                    Route::put($kind.'/{id}', [SchedulingController::class, 'update'])->whereUuid('id')->defaults('resourceKind', $kind);
                }
                Route::get('appointments/configuration-services/export', [ServiceCatalogController::class, 'export']);
                foreach (['appointments/configuration-services' => 'services', 'service-categories' => 'categories'] as $path => $kind) {
                    Route::get($path, [ServiceCatalogController::class, 'index'])->defaults('catalogKind', $kind);
                    Route::post($path, [ServiceCatalogController::class, 'store'])->defaults('catalogKind', $kind);
                    Route::put($path.'/{id}', [ServiceCatalogController::class, 'update'])->whereUuid('id')->defaults('catalogKind', $kind);
                    Route::post($path.'/{id}/status', [ServiceCatalogController::class, 'status'])->whereUuid('id')->defaults('catalogKind', $kind);
                }
                Route::get('appointments/configuration-services/{id}', [ServiceCatalogController::class, 'show'])->whereUuid('id');
                Route::get('doctors/{doctor}/availability', [SchedulingController::class, 'availability'])->whereUuid('doctor');
                Route::get('doctor-workspace', DoctorWorkspaceController::class);
                Route::get('visits/{visit}/clinical-notes', [VisitClinicalNoteController::class, 'show'])->whereUuid('visit');
                Route::put('visits/{visit}/clinical-notes', [VisitClinicalNoteController::class, 'save'])->whereUuid('visit');
                Route::get('visits/{visit}/recommendations', [VisitRecommendationController::class, 'show'])->whereUuid('visit');
                Route::put('visits/{visit}/recommendations', [VisitRecommendationController::class, 'save'])->whereUuid('visit');
                Route::get('visits/{visit}/referrals', [VisitReferralController::class, 'index'])->whereUuid('visit');
                Route::get('visits/{visit}/referral-options', [VisitReferralController::class, 'options'])->whereUuid('visit');
                Route::post('visits/{visit}/referrals', [VisitReferralController::class, 'store'])->whereUuid('visit');
                Route::get('visits/{visit}/prescriptions', [VisitPrescriptionController::class, 'index'])->whereUuid('visit');
                Route::post('visits/{visit}/prescriptions', [VisitPrescriptionController::class, 'store'])->whereUuid('visit');
                Route::put('visits/{visit}/prescriptions/{prescription}', [VisitPrescriptionController::class, 'update'])->whereUuid('visit')->whereUuid('prescription');
                Route::get('visits/{visit}/diagnosis', [VisitDiagnosisController::class, 'show'])->whereUuid('visit');
                Route::put('visits/{visit}/diagnosis', [VisitDiagnosisController::class, 'save'])->whereUuid('visit');
                Route::get('queue-tickets', [QueueController::class, 'index']);
                Route::post('queue-tickets', [QueueController::class, 'store']);
                Route::get('queue-tickets/{ticket}', [QueueController::class, 'show'])->whereUuid('ticket');
                Route::get('queue-tickets/{ticket}/own-start-slot', [QueueController::class, 'ownStartSlot'])->whereUuid('ticket');
                Route::get('queue-tickets/{ticket}/rebooking-options', [QueueController::class, 'rebookingOptions'])->whereUuid('ticket');
                foreach (['call', 'recall', 'start', 'complete', 'skip', 'cancel', 'requeue'] as $operation) {
                    Route::post('queue-tickets/{ticket}/'.$operation, [QueueController::class, 'mutate'])->whereUuid('ticket')->defaults('operation', $operation);
                }
                Route::get('reception/overview', [QueueController::class, 'overview']);
                Route::get('appointments/booking-options', [AppointmentController::class, 'bookingOptions']);
                Route::get('appointments/{appointment}/doctor-rebooking-options', [AppointmentController::class, 'doctorRebookingOptions'])->whereUuid('appointment');

                Route::get('appointments/export', [AppointmentController::class, 'export']);
                foreach (['appointments' => 'appointment', 'visits' => 'visit'] as $kind => $parameter) {
                    Route::get($kind, [AppointmentController::class, 'index'])->defaults('resourceKind', $kind);
                    Route::post($kind, [AppointmentController::class, 'store'])->defaults('resourceKind', $kind);
                    Route::get($kind.'/{'.$parameter.'}', [AppointmentController::class, 'show'])->whereUuid($parameter)->defaults('resourceKind', $kind);
                    Route::get($kind.'/{'.$parameter.'}/history', [AppointmentController::class, 'history'])->whereUuid($parameter)->defaults('resourceKind', $kind);
                    Route::get('patients/{patient}/'.$kind, [AppointmentController::class, 'index'])->whereUuid('patient')->defaults('resourceKind', $kind);
                }
                foreach (['reschedule' => 'reschedule', 'cancel' => 'cancel', 'no-show' => 'no_show', 'check-in' => 'check_in'] as $path => $operation) {
                    Route::post('appointments/{appointment}/'.$path, [AppointmentController::class, 'mutate'])->whereUuid('appointment')->defaults('operation', $operation);
                }
                Route::get('patients/export', [PatientController::class, 'export']);
                Route::get('patients', [PatientController::class, 'index']);
                Route::post('patients', [PatientController::class, 'store']);
                Route::get('patients/{patient}', [PatientController::class, 'show'])->whereUuid('patient');
                Route::patch('patients/{patient}', [PatientController::class, 'update'])->whereUuid('patient');
                Route::post('patients/{patient}/branches', [PatientController::class, 'link'])->whereUuid('patient');
                Route::post('patients/{patient}/archive', [PatientController::class, 'archive'])->whereUuid('patient');
                Route::post('patients/{patient}/restore', [PatientController::class, 'restore'])->whereUuid('patient');
                Route::get('context', ContextController::class);
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
