<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Billing\InvoiceController;
use App\Http\Controllers\Api\Clinical\ConsultationController;
use App\Http\Controllers\Api\Clinical\PrescriptionController;
use App\Http\Controllers\Api\DoctorScheduleController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Support\Facades\Route;

Route::post('/patients/register', [PatientController::class, 'register'])->middleware(['jwt.auth:optional', 'throttle:10,1']);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');
Route::post('/auth/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1');
Route::post('/auth/mfa/verify', [AuthController::class, 'verifyMfa'])->middleware(['jwt.auth:optional', 'throttle:5,1']);

Route::middleware(['jwt.auth'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/mfa/setup', [AuthController::class, 'setupMfa'])->middleware('role:owner');
    Route::get('/staff', [StaffController::class, 'index'])->middleware('role:owner,admin');
    Route::post('/staff', [StaffController::class, 'store'])->middleware('role:owner,admin');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->middleware('role:owner,admin');
    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->whereNumber('patient');
    Route::get('/doctors/{doctor}/available-slots', [AppointmentController::class, 'availableSlots'])->whereNumber('doctor');
    Route::get('/doctors/{doctor}/schedules', [DoctorScheduleController::class, 'index'])->whereNumber('doctor');
    Route::post('/doctors/{doctor}/schedules', [DoctorScheduleController::class, 'store'])->whereNumber('doctor');
    Route::get('/appointments/meta/statuses', [AppointmentController::class, 'statuses']);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->whereNumber('appointment');
    Route::post('/consultations', [ConsultationController::class, 'store'])
        ->middleware('doctor');

    Route::post('/consultations/{consultation}/prescriptions', [PrescriptionController::class, 'store'])
        ->whereNumber('consultation')
        ->middleware('doctor');

    Route::get('/prescriptions/{prescription}/print', [PrescriptionController::class, 'print'])
        ->whereNumber('prescription');

    Route::post('/invoices/generate', [InvoiceController::class, 'generate']);
    Route::patch('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->whereNumber('invoice');
    Route::patch('/invoices/{invoice}/discount', [InvoiceController::class, 'discount'])->whereNumber('invoice');
});
