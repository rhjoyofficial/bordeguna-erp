<?php

use App\Modules\Hrm\Http\Controllers\AttendanceClockController;
use App\Modules\Hrm\Http\Controllers\AttendanceCorrectionController;
use App\Modules\Hrm\Http\Controllers\AttendanceImportController;
use App\Modules\Hrm\Http\Controllers\AttendancePunchController;
use App\Modules\Hrm\Http\Controllers\DailyAttendanceController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\LeaveController;
use App\Modules\Hrm\Http\Controllers\SelfLeaveController;
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
    Route::post('clock/corrections', [AttendanceClockController::class, 'requestCorrection'])->name('clock.corrections.store');

    // Attendance capture (ATT-01/03/08). The punch log has no edit or delete
    // route by design — raw punches are immutable.
    Route::get('attendance', [AttendancePunchController::class, 'index'])->name('attendance.index');
    Route::post('attendance', [AttendancePunchController::class, 'store'])->name('attendance.store');
    Route::get('attendance/import', [AttendanceImportController::class, 'index'])->name('attendance.import.index');
    Route::post('attendance/import', [AttendanceImportController::class, 'store'])->name('attendance.import.store');

    // Processed attendance (POL-02..12). Derived and recalculable, unlike the
    // punches beneath it; finalising is the POL-06 human review step.
    Route::get('attendance/daily', [DailyAttendanceController::class, 'index'])->name('attendance.daily.index');
    Route::post('attendance/daily/process', [DailyAttendanceController::class, 'process'])->name('attendance.daily.process');
    Route::post('attendance/daily/finalize', [DailyAttendanceController::class, 'finalize'])->name('attendance.daily.finalize');

    // Corrections (COR-01..06). Approve/reject are their own endpoints because a
    // correction is its own approvable type with its own applier — the raw
    // punches it adjusts are never edited.
    Route::get('attendance/corrections', [AttendanceCorrectionController::class, 'index'])->name('attendance.corrections.index');
    Route::get('attendance/corrections/punches', [AttendanceCorrectionController::class, 'punches'])->name('attendance.corrections.punches');
    Route::post('attendance/corrections', [AttendanceCorrectionController::class, 'store'])->name('attendance.corrections.store');
    Route::post('attendance/corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->name('attendance.corrections.approve');
    Route::post('attendance/corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->name('attendance.corrections.reject');

    // Period locks (COR-05) — the deadline COR-02 hangs on.
    Route::post('attendance/period-locks', [AttendanceCorrectionController::class, 'lock'])->name('attendance.locks.store');
    Route::patch('attendance/period-locks/{lock}/unlock', [AttendanceCorrectionController::class, 'unlock'])->name('attendance.locks.unlock');

    // Employee self-service leave and movement — self-scope, no manage gate.
    Route::get('my-leave', [SelfLeaveController::class, 'show'])->name('my-leave');
    Route::post('my-leave/requests', [SelfLeaveController::class, 'storeLeave'])->name('my-leave.requests.store');
    Route::post('my-leave/movements', [SelfLeaveController::class, 'storeMovement'])->name('my-leave.movements.store');

    // Leave & movement administration (LEV-01..07, POL-09/10).
    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
    Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
    Route::post('leave/quota', [LeaveController::class, 'grantQuota'])->name('leave.quota');

    Route::post('movements', [LeaveController::class, 'storeMovement'])->name('movements.store');
    Route::post('movements/{movement}/approve', [LeaveController::class, 'approveMovement'])->name('movements.approve');
    Route::post('movements/{movement}/reject', [LeaveController::class, 'rejectMovement'])->name('movements.reject');

    // Corrections C6b/C6d — preview and commit share one computation.
    Route::get('leave/year-end', [LeaveController::class, 'yearEnd'])->name('leave.year-end');
    Route::post('leave/year-end', [LeaveController::class, 'runYearEnd'])->name('leave.year-end.run');
});
