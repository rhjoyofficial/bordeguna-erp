<?php

use App\Modules\Hrm\Http\Controllers\AttendanceClockController;
use App\Modules\Hrm\Http\Controllers\AttendanceCorrectionController;
use App\Modules\Hrm\Http\Controllers\AttendanceImportController;
use App\Modules\Hrm\Http\Controllers\AttendancePunchController;
use App\Modules\Hrm\Http\Controllers\DailyAttendanceController;
use App\Modules\Hrm\Http\Controllers\DisbursementController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\HolidayCalendarController;
use App\Modules\Hrm\Http\Controllers\HrmDashboardController;
use App\Modules\Hrm\Http\Controllers\LeaveController;
use App\Modules\Hrm\Http\Controllers\LeaveProvisionController;
use App\Modules\Hrm\Http\Controllers\LoanController;
use App\Modules\Hrm\Http\Controllers\PayrollController;
use App\Modules\Hrm\Http\Controllers\PayrollInputController;
use App\Modules\Hrm\Http\Controllers\PayslipController;
use App\Modules\Hrm\Http\Controllers\ReportController;
use App\Modules\Hrm\Http\Controllers\SalaryController;
use App\Modules\Hrm\Http\Controllers\SalaryRevisionController;
use App\Modules\Hrm\Http\Controllers\ScheduleController;
use App\Modules\Hrm\Http\Controllers\SelfLeaveController;
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
    Route::post('calendars/{calendar}/working-days', [HolidayCalendarController::class, 'storeWorkingDay'])->name('calendars.working-days.store');
    Route::delete('calendars/{calendar}/working-days/{override}', [HolidayCalendarController::class, 'destroyWorkingDay'])->name('calendars.working-days.destroy');

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

    // Salary structure (SAL-01..06, C3, C7c). Read is gated by
    // hrm.salary.manage OR hrm.salary.view per Correction C5 / ACL-02; writes
    // need manage. Department Managers hold neither.
    Route::get('salary', [SalaryController::class, 'index'])->name('salary.index');
    Route::get('salary/{employee}', [SalaryController::class, 'show'])->name('salary.show');
    Route::post('salary/{employee}', [SalaryController::class, 'store'])->name('salary.store');
    Route::post('salary-components', [SalaryController::class, 'storeComponent'])->name('salary.components.store');
    Route::put('salary-components/{component}', [SalaryController::class, 'updateComponent'])->name('salary.components.update');
    Route::patch('salary-components/{component}/toggle', [SalaryController::class, 'toggleComponent'])->name('salary.components.toggle');

    // Increments (ADJ-06) and the arrears SAL-05 produces. Read is gated by
    // hrm.salary.manage OR hrm.salary.view per C5/ACL-02, like every salary
    // screen; approve/reject authorise by workflow stage instead.
    Route::get('salary-revisions', [SalaryRevisionController::class, 'index'])->name('revisions.index');
    Route::post('salary-revisions', [SalaryRevisionController::class, 'store'])->name('revisions.store');
    Route::post('salary-revisions/{revision}/approve', [SalaryRevisionController::class, 'approve'])->name('revisions.approve');
    Route::post('salary-revisions/{revision}/reject', [SalaryRevisionController::class, 'reject'])->name('revisions.reject');

    // Payroll inputs — the money the salary structure does not describe:
    // overtime (ADJ-01..04), bonus (ADJ-05), adjustments (ADJ-08/09) and the
    // PAY-11 carry-forward balance. Each write authorises hrm.payroll.manage;
    // each approval authorises by workflow stage.
    Route::get('payroll-inputs', [PayrollInputController::class, 'index'])->name('payroll.inputs.index');

    Route::post('overtime', [PayrollInputController::class, 'storeOvertime'])->name('overtime.store');
    Route::post('overtime/{overtime}/approve', [PayrollInputController::class, 'approveOvertime'])->name('overtime.approve');
    Route::post('overtime/{overtime}/reject', [PayrollInputController::class, 'rejectOvertime'])->name('overtime.reject');

    Route::post('bonus-events', [PayrollInputController::class, 'storeBonusEvent'])->name('bonus.events.store');
    Route::post('bonus-events/{event}/awards', [PayrollInputController::class, 'awardBonus'])->name('bonus.awards.store');
    Route::post('bonus-awards/{award}/approve', [PayrollInputController::class, 'approveBonus'])->name('bonus.awards.approve');
    Route::post('bonus-awards/{award}/reject', [PayrollInputController::class, 'rejectBonus'])->name('bonus.awards.reject');

    Route::post('payroll-adjustments', [PayrollInputController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('payroll-adjustments/{adjustment}/approve', [PayrollInputController::class, 'approveAdjustment'])->name('adjustments.approve');
    Route::post('payroll-adjustments/{adjustment}/reject', [PayrollInputController::class, 'rejectAdjustment'])->name('adjustments.reject');

    // Loans and salary advances (ADJ-07). Approval disburses — before that a
    // loan owes nothing, so payroll deducts nothing.
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::get('loans/preview', [LoanController::class, 'preview'])->name('loans.preview');
    Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->name('loans.approve');
    Route::post('loans/{loan}/reject', [LoanController::class, 'reject'])->name('loans.reject');
    Route::post('loans/{loan}/settle', [LoanController::class, 'settle'])->name('loans.settle');

    // PAY-11's manual resolution: carry a shortfall forward, then recalculate.
    Route::post('payslips/{payslip}/carry-forward', [PayrollInputController::class, 'carryForward'])->name('payslips.carry-forward');

    // The leave encashment provision (coverage item 5). Preview and commit run
    // the same computation; each action authorises hrm.payroll.manage.
    // ⚠ The literal segment is registered before the numeric wildcard.
    Route::get('leave-provision', [LeaveProvisionController::class, 'index'])->name('leave-provision.index');
    Route::post('leave-provision', [LeaveProvisionController::class, 'store'])->name('leave-provision.store');
    Route::get('leave-provision/{leaveProvision}', [LeaveProvisionController::class, 'show'])
        ->whereNumber('leaveProvision')->name('leave-provision.show');

    // Payroll calculation (PAY-01..13). The WFL-02 lifecycle beyond
    // Draft/Calculated arrives in Phase 3c.
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('payroll/calculate', [PayrollController::class, 'calculate'])->name('payroll.calculate');
    Route::get('payroll/{run}/progress', [PayrollController::class, 'progress'])->name('payroll.progress');

    // WFL-02 lifecycle. Approve/reject go through the shared workflow engine,
    // so these endpoints authorise by stage rather than by permission.
    Route::post('payroll/{run}/review', [PayrollController::class, 'review'])->name('payroll.review');
    Route::post('payroll/{run}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
    Route::post('payroll/{run}/reject', [PayrollController::class, 'reject'])->name('payroll.reject');
    Route::post('payroll/{run}/disburse', [PayrollController::class, 'disburse'])->name('payroll.disburse');
    Route::post('payroll/{run}/lock', [PayrollController::class, 'lock'])->name('payroll.lock');
    Route::post('payroll/{run}/reopen', [PayrollController::class, 'reopen'])->name('payroll.reopen');
    Route::post('payroll/{run}/cancel', [PayrollController::class, 'cancel'])->name('payroll.cancel');

    // Payslips (DIS-03/04). Read is self-scope OR hrm.payroll.manage, decided in
    // the controller — Department Managers hold neither, per Correction C5.
    Route::get('my-payslips', [PayslipController::class, 'mine'])->name('my-payslips');
    Route::get('payslips/{payslip}', [PayslipController::class, 'show'])->name('payslips.show');
    Route::get('payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])->name('payslips.pdf');
    Route::post('payslips/{payslip}/email', [PayslipController::class, 'email'])->name('payslips.email');
    Route::post('payroll/{run}/email-payslips', [PayslipController::class, 'emailRun'])->name('payroll.email-payslips');

    // Disbursement (DIS-01/02/05/06). Everything here follows the Disbursed
    // transition — it records the money actually moving, and what happens when
    // it does not. Each action authorises hrm.payroll.manage.
    Route::get('payroll/{run}/disbursement', [DisbursementController::class, 'index'])->name('payroll.disbursement');
    Route::post('payroll/{run}/batches', [DisbursementController::class, 'storeBatches'])->name('payroll.batches.store');
    Route::get('payroll/{run}/accounting-export', [DisbursementController::class, 'exportAccounting'])->name('payroll.accounting-export');

    Route::post('disbursement-batches/{batch}/confirm', [DisbursementController::class, 'confirmBatch'])->name('batches.confirm');
    Route::post('disbursement-batches/{batch}/fail', [DisbursementController::class, 'failBatch'])->name('batches.fail');
    Route::post('disbursement-batches/{batch}/advice', [DisbursementController::class, 'uploadAdvice'])->name('batches.advice');
    Route::get('disbursement-batches/{batch}/register', [DisbursementController::class, 'exportRegister'])->name('batches.register');

    Route::post('payslips/{payslip}/payments', [DisbursementController::class, 'storePayment'])->name('payslips.payments.store');
    Route::post('payslips/{payslip}/payments/fail', [DisbursementController::class, 'failPayment'])->name('payslips.payments.fail');
    Route::post('payslip-payments/{payment}/reverse', [DisbursementController::class, 'reversePayment'])->name('payslips.payments.reverse');

    // WFL-08 unlock: HR Head → Finance → Management, or a Super Admin override.
    Route::post('payroll/{run}/unlock-requests', [PayrollController::class, 'requestUnlock'])->name('payroll.unlock.store');
    Route::post('payroll-unlock-requests/{unlock}/approve', [PayrollController::class, 'approveUnlock'])->name('payroll.unlock.approve');
    Route::post('payroll-unlock-requests/{unlock}/reject', [PayrollController::class, 'rejectUnlock'])->name('payroll.unlock.reject');

    // Reporting (W3, RPT-01/02/03). One set of routes for every report — the
    // {report} key resolves through the ReportRegistry, which applies both the
    // declared permission and Correction C10d's ACL-02 rule. A report the user
    // may not run 404s rather than 403s, so the key list gives nothing away.
    //
    // `run` is a GET because a report is a link people bookmark, share and
    // re-open; it changes nothing on the server beyond the activity-log entry
    // that records who read what.
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/downloads/{file}', [ReportController::class, 'download'])->name('reports.download');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('reports/{report}/run', [ReportController::class, 'run'])->name('reports.run');

    // RPT-04. Deliberately a separate route and controller from `reports`, not a
    // tab inside it: report generation and dashboard rendering are separate
    // concerns that share only the query layer. No permission middleware here —
    // the registry filters widget by widget, so a user with only attendance
    // rights still gets a dashboard, just a shorter one.
    Route::get('dashboard', [HrmDashboardController::class, 'index'])->name('dashboard');
});
