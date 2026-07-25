<?php

use App\Modules\Hrm\Http\Controllers\AttendanceClockController;
use App\Modules\Hrm\Http\Controllers\AttendanceImportController;
use App\Modules\Hrm\Http\Controllers\AttendancePunchController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\HolidayCalendarController;
use App\Modules\Hrm\Http\Controllers\ScheduleController;
use App\Modules\Hrm\Http\Controllers\SelfServiceController;
use App\Modules\Hrm\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    // Employee Self-Service — a user's own record. No hrm.employees.manage
    // gate: access is self-scope (P3), resolved from the signed-in user. Master
    // data is read-only; changes go through an approval-gated Update Request.
    Route::get('my-profile', [SelfServiceController::class, 'show'])->name('my-profile');
    Route::post('my-profile/change-requests', [SelfServiceController::class, 'requestChange'])->name('my-profile.change-requests.store');

    // Each action authorizes hrm.employees.manage internally; {employee} is a
    // central record, confined to the current business in the controller.
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::patch('employees/{employee}/status', [EmployeeController::class, 'status'])->name('employees.status');
    Route::post('employees/{employee}/separation', [EmployeeController::class, 'separate'])->name('employees.separate');
    Route::post('employees/{employee}/transfer', [EmployeeController::class, 'transfer'])->name('employees.transfer');
    Route::post('employees/{employee}/documents', [EmployeeController::class, 'uploadDocument'])->name('employees.documents.store');
    Route::delete('employees/{employee}/documents/{attachment}', [EmployeeController::class, 'destroyDocument'])->name('employees.documents.destroy');

    // Scheduling — shift definitions (SCH-02). Each action authorizes
    // hrm.scheduling.manage; {shift} is business-scoped so binding 404s cross-tenant.
    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::put('shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::patch('shifts/{shift}/toggle', [ShiftController::class, 'toggle'])->name('shifts.toggle');
    Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

    // Scheduling — named/versioned holiday calendars (SCH-06/07) + their holidays.
    Route::get('calendars', [HolidayCalendarController::class, 'index'])->name('calendars.index');
    Route::post('calendars', [HolidayCalendarController::class, 'store'])->name('calendars.store');
    Route::put('calendars/{calendar}', [HolidayCalendarController::class, 'update'])->name('calendars.update');
    Route::patch('calendars/{calendar}/toggle', [HolidayCalendarController::class, 'toggle'])->name('calendars.toggle');
    Route::delete('calendars/{calendar}', [HolidayCalendarController::class, 'destroy'])->name('calendars.destroy');
    Route::post('calendars/{calendar}/holidays', [HolidayCalendarController::class, 'storeHoliday'])->name('calendars.holidays.store');
    Route::delete('calendars/{calendar}/holidays/{holiday}', [HolidayCalendarController::class, 'destroyHoliday'])->name('calendars.holidays.destroy');

    // Scheduling — binding shifts/calendars to groups and individuals (SCH-01/06/07)
    // and the resulting roster. {schedule} is business-scoped.
    Route::get('schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])->name('schedules.store');
    Route::patch('schedules/{assignment}/end', [ScheduleController::class, 'end'])->name('schedules.end');
    Route::delete('schedules/{assignment}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');

    // Employee self-service clock-in (ATT-01). Self-scope like My Profile — no
    // hrm.attendance.manage gate; the employee is resolved from the signed-in user.
    Route::get('clock', [AttendanceClockController::class, 'show'])->name('clock');
    Route::post('clock', [AttendanceClockController::class, 'store'])->name('clock.store');

    // Attendance capture (ATT-01/03/08). The punch log has no edit or delete
    // route by design — raw punches are immutable.
    Route::get('attendance', [AttendancePunchController::class, 'index'])->name('attendance.index');
    Route::post('attendance', [AttendancePunchController::class, 'store'])->name('attendance.store');
    Route::get('attendance/import', [AttendanceImportController::class, 'index'])->name('attendance.import.index');
    Route::post('attendance/import', [AttendanceImportController::class, 'store'])->name('attendance.import.store');
});
