<?php

use App\Modules\Hrm\Http\Controllers\AttendanceClockController;
use App\Modules\Hrm\Http\Controllers\AttendanceCorrectionController;
use App\Modules\Hrm\Http\Controllers\AttendanceImportController;
use App\Modules\Hrm\Http\Controllers\AttendancePolicyController;
use App\Modules\Hrm\Http\Controllers\AttendancePunchController;
use App\Modules\Hrm\Http\Controllers\AttendanceSummaryController;
use App\Modules\Hrm\Http\Controllers\AttendanceWaiverController;
use App\Modules\Hrm\Http\Controllers\ChecklistController;
use App\Modules\Hrm\Http\Controllers\DailyAttendanceController;
use App\Modules\Hrm\Http\Controllers\DisbursementController;
use App\Modules\Hrm\Http\Controllers\EmployeeAttendanceController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\ExpenseCategoryController;
use App\Modules\Hrm\Http\Controllers\ExpenseClaimController;
use App\Modules\Hrm\Http\Controllers\HolidayCalendarController;
use App\Modules\Hrm\Http\Controllers\HolidayWorkController;
use App\Modules\Hrm\Http\Controllers\HrLetterController;
use App\Modules\Hrm\Http\Controllers\HrmDashboardController;
use App\Modules\Hrm\Http\Controllers\LeaveController;
use App\Modules\Hrm\Http\Controllers\LeaveTypeController;
use App\Modules\Hrm\Http\Controllers\LoanController;
use App\Modules\Hrm\Http\Controllers\MyTeamController;
use App\Modules\Hrm\Http\Controllers\OrgChartController;
use App\Modules\Hrm\Http\Controllers\PayrollController;
use App\Modules\Hrm\Http\Controllers\PayrollHistoryController;
use App\Modules\Hrm\Http\Controllers\PayrollInputController;
use App\Modules\Hrm\Http\Controllers\PayslipController;
use App\Modules\Hrm\Http\Controllers\PerformanceController;
use App\Modules\Hrm\Http\Controllers\RecruitmentController;
use App\Modules\Hrm\Http\Controllers\ReportController;
use App\Modules\Hrm\Http\Controllers\SalaryController;
use App\Modules\Hrm\Http\Controllers\SalaryRevisionController;
use App\Modules\Hrm\Http\Controllers\ScheduleController;
use App\Modules\Hrm\Http\Controllers\SelfLeaveController;
use App\Modules\Hrm\Http\Controllers\SelfServiceController;
use App\Modules\Hrm\Http\Controllers\SettlementController;
use App\Modules\Hrm\Http\Controllers\ShiftController;
use App\Modules\Hrm\Http\Controllers\ShowCauseNoticeController;
use App\Modules\Hrm\Http\Controllers\StaffPurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    // Employee Self-Service — a user's own record. No hrm.employees.manage
    // gate: access is self-scope (P3), resolved from the signed-in user. Master
    // data is read-only; changes go through an approval-gated Update Request.
    Route::get('my-profile', [SelfServiceController::class, 'show'])->name('my-profile');
    Route::post('my-profile/change-requests', [SelfServiceController::class, 'requestChange'])->name('my-profile.change-requests.store');

    // Each action authorizes hrm.employees.manage internally; {employee} is a
    // central record, confined to the current business in the controller.
    // The organization chart (Module 1, Slice 1) - the phone book's shape,
    // open to every signed-in member; see OrgChartController for the why.
    Route::get('org-chart', [OrgChartController::class, 'show'])->name('org-chart');
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::patch('employees/{employee}/status', [EmployeeController::class, 'status'])->name('employees.status');
    Route::post('employees/{employee}/login-account', [EmployeeController::class, 'createLoginAccount'])->name('employees.login-account');
    Route::patch('employees/{employee}/login-email', [EmployeeController::class, 'updateLoginEmail'])->name('employees.login-email');
    Route::patch('employees/{employee}/remote-clock-in', [EmployeeController::class, 'setRemoteClockIn'])->name('employees.remote-clock-in');

    // The final settlement statement. Pay data, so it sits behind the payroll
    // permission rather than the employee one, and it pays nobody.
    Route::get('employees/{employee}/settlement', [SettlementController::class, 'show'])->name('employees.settlement');
    Route::post('employees/{employee}/confirm', [EmployeeController::class, 'confirm'])->name('employees.confirm');
    // HR letters: drafted from a frozen snapshot, signed by the boss.
    Route::get('employees/{employee}/letters', [HrLetterController::class, 'index'])->name('employees.letters');
    Route::post('employees/{employee}/letters', [HrLetterController::class, 'store'])->name('employees.letters.store');
    Route::get('letters/{letter}/print', [HrLetterController::class, 'print'])->name('letters.print');
    // Joiner / leaver checklists: who ticked what, when.
    Route::get('employees/{employee}/checklist', [ChecklistController::class, 'show'])->name('employees.checklist');
    Route::post('employees/{employee}/checklist/{item}/toggle', [ChecklistController::class, 'toggle'])->name('employees.checklist.toggle');
    // Performance phase 1: goals with outcomes, one review per period.
    Route::get('employees/{employee}/performance', [PerformanceController::class, 'show'])->name('employees.performance');
    Route::post('employees/{employee}/goals', [PerformanceController::class, 'storeGoal'])->name('employees.goals.store');
    Route::post('employees/{employee}/reviews', [PerformanceController::class, 'storeReview'])->name('employees.reviews.store');
    Route::post('goals/{goal}/outcome', [PerformanceController::class, 'outcome'])->name('goals.outcome');
    Route::post('employees/{employee}/separation', [EmployeeController::class, 'separate'])->name('employees.separate');
    Route::post('employees/{employee}/transfer', [EmployeeController::class, 'transfer'])->name('employees.transfer');
    Route::post('employees/{employee}/documents', [EmployeeController::class, 'uploadDocument'])->name('employees.documents.store');
    Route::delete('employees/{employee}/documents/{attachment}', [EmployeeController::class, 'destroyDocument'])->name('employees.documents.destroy');
    Route::post('employees/{employee}/photo', [EmployeeController::class, 'uploadPhoto'])->name('employees.photo.store');
    Route::delete('employees/{employee}/photo', [EmployeeController::class, 'destroyPhoto'])->name('employees.photo.destroy');
    // The digital signature drawn onto reports - same shape as the photo.
    Route::post('employees/{employee}/signature', [EmployeeController::class, 'uploadSignature'])->name('employees.signature.store');
    Route::delete('employees/{employee}/signature', [EmployeeController::class, 'destroySignature'])->name('employees.signature.destroy');

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
    Route::get('attendance/summary', [AttendanceSummaryController::class, 'index'])->name('attendance.summary.index');
    // One employee's attendance on its own page - reached from the summary
    // rows and the employee record, so it carries no sidebar entry of its own.
    Route::get('attendance/employees/{employee}', [EmployeeAttendanceController::class, 'show'])->name('attendance.employee.show');
    Route::post('attendance/daily/process', [DailyAttendanceController::class, 'process'])->name('attendance.daily.process');
    Route::post('attendance/daily/finalize', [DailyAttendanceController::class, 'finalize'])->name('attendance.daily.finalize');

    // Forgiving a day's deduction (24 Aug 2026). Its own permission,
    // deliberately NOT bundled with hrm.attendance.manage: recording what
    // happened and deciding money are different powers - see the controller.
    Route::middleware('can:hrm.attendance.waive')->group(function () {
        Route::post('attendance/waivers', [AttendanceWaiverController::class, 'store'])->name('attendance.waivers.store');
        Route::post('attendance/waivers/{waiver}/revoke', [AttendanceWaiverController::class, 'revoke'])->name('attendance.waivers.revoke');
    });

    // Show cause notices (Joy, 24 Aug 2026): HR's formal letter after a
    // lateness review, and the employee's own copies. HR reads and issues
    // under hrm.attendance.manage; my-notices is self-scope like my-profile.
    // ⚠ The literal `my-notices` segments register before nothing conflicting,
    // but the {notice} wildcard still gets whereNumber - the invoices lesson.
    Route::get('notices', [ShowCauseNoticeController::class, 'index'])->name('notices.index');
    Route::post('notices', [ShowCauseNoticeController::class, 'store'])->name('notices.store');
    Route::get('notices/{notice}', [ShowCauseNoticeController::class, 'show'])
        ->whereNumber('notice')->name('notices.show');
    Route::get('my-notices', [ShowCauseNoticeController::class, 'selfIndex'])->name('my-notices.index');
    Route::get('my-notices/{notice}', [ShowCauseNoticeController::class, 'selfShow'])
        ->whereNumber('notice')->name('my-notices.show');

    // Corrections (COR-01..06). Approve/reject are their own endpoints because a
    // correction is its own approvable type with its own applier — the raw
    // punches it adjusts are never edited.
    Route::get('attendance/corrections', [AttendanceCorrectionController::class, 'index'])->name('attendance.corrections.index');
    Route::get('attendance/corrections/punches', [AttendanceCorrectionController::class, 'punches'])->name('attendance.corrections.punches');
    Route::post('attendance/corrections', [AttendanceCorrectionController::class, 'store'])->name('attendance.corrections.store');
    Route::post('attendance/corrections/{correction}/approve', [AttendanceCorrectionController::class, 'approve'])->name('attendance.corrections.approve');
    Route::post('attendance/corrections/{correction}/reject', [AttendanceCorrectionController::class, 'reject'])->name('attendance.corrections.reject');

    // Attendance policy as data (POL-03/12). A revision closes the current
    // version and opens the next from a chosen date - never an edit in place,
    // so a finalised day keeps the version that judged it.
    Route::get('policies', [AttendancePolicyController::class, 'index'])->name('policies.index');
    Route::post('policies/{policy}/revise', [AttendancePolicyController::class, 'revise'])->name('policies.revise');

    // Period locks (COR-05) — the deadline COR-02 hangs on.
    Route::post('attendance/period-locks', [AttendanceCorrectionController::class, 'lock'])->name('attendance.locks.store');
    Route::patch('attendance/period-locks/{lock}/unlock', [AttendanceCorrectionController::class, 'unlock'])->name('attendance.locks.unlock');

    // The manager's team view - self-scope: whoever supervises sees theirs;
    // read-only, approvals stay with the inbox.
    Route::get('my-team', [MyTeamController::class, 'show'])->name('my-team');

    // Employee self-service leave and movement — self-scope, no manage gate.
    Route::get('my-leave', [SelfLeaveController::class, 'show'])->name('my-leave');
    Route::post('my-leave/requests', [SelfLeaveController::class, 'storeLeave'])->name('my-leave.requests.store');
    Route::post('my-leave/movements', [SelfLeaveController::class, 'storeMovement'])->name('my-leave.movements.store');

    // Employee expense claims. Self-scope: raising and seeing your own needs no
    // management permission, exactly like the clock and My Leave.
    // The Staff Product Benefit (office order BDG/HR/09/01/2026) -
    // self-scope to apply; the authority's screens sit below.
    Route::get('staff-purchase', [StaffPurchaseController::class, 'mine'])->name('staff-purchase');
    Route::post('staff-purchase', [StaffPurchaseController::class, 'apply'])->name('staff-purchase.apply');
    Route::get('staff-purchases', [StaffPurchaseController::class, 'index'])->name('staff-purchases.index');
    Route::post('staff-purchases/{id}/approve', [StaffPurchaseController::class, 'approve'])->name('staff-purchases.approve');
    Route::post('staff-purchases/{id}/reject', [StaffPurchaseController::class, 'reject'])->name('staff-purchases.reject');
    Route::post('staff-purchases/{id}/fulfill', [StaffPurchaseController::class, 'fulfill'])->name('staff-purchases.fulfill');
    // Phase 2 (17 Oct 2026): an approved application withdrawn before the
    // handover (reservation released), and a zero-charge defect replacement.
    Route::post('staff-purchases/{id}/cancel', [StaffPurchaseController::class, 'cancel'])->name('staff-purchases.cancel');
    Route::post('staff-purchases/{id}/replace', [StaffPurchaseController::class, 'replace'])->name('staff-purchases.replace');
    // ⭐ D-D (18 Oct 2026): an open credit purchase moved to the salary deduction.
    Route::post('staff-purchases/{id}/to-salary', [StaffPurchaseController::class, 'toSalary'])->name('staff-purchases.to-salary');

    // ⚠⚠ A claim is a DRAFT the employee builds and then sends (Joy, 9 Sep
    // 2026). `store` adds one spend and, when asked, submits the whole claim in
    // the same press - so a single courier claim is still one press while a
    // market trip is one claim rather than four. Everything below it edits a
    // draft, which is the only state an employee may change.
    Route::get('my-expenses', [ExpenseClaimController::class, 'mine'])->name('my-expenses');
    Route::post('my-expenses', [ExpenseClaimController::class, 'store'])->name('my-expenses.store');
    Route::post('my-expenses/{claim}/submit', [ExpenseClaimController::class, 'submit'])->name('my-expenses.submit');
    Route::patch('my-expenses/{claim}', [ExpenseClaimController::class, 'rename'])->name('my-expenses.rename');
    Route::delete('my-expenses/{claim}', [ExpenseClaimController::class, 'destroy'])->name('my-expenses.destroy');
    Route::delete('my-expenses/{claim}/lines/{line}', [ExpenseClaimController::class, 'destroyLine'])->name('my-expenses.lines.destroy');
    // ⭐ Joy, 15 Sep 2026: a spend on the open claim - including one sent back -
    // is corrected in place. POST, because a replacement receipt travels as a file.
    Route::post('my-expenses/{claim}/lines/{line}', [ExpenseClaimController::class, 'updateLine'])->name('my-expenses.lines.update');

    // ── Recruitment (Joy, 29 Aug 2026) ──────────────────────────────────────
    // ⚠⚠ NOTHING HERE CREATES AN EMPLOYEE. Marking somebody hired records the
    // decision; the employee record is made in People, where a joining date, an
    // employment type, a probation length and a salary structure are asked for.
    Route::middleware('can:recruitment.view')->group(function () {
        Route::get('recruitment', [RecruitmentController::class, 'index'])->name('recruitment.index');
    });

    Route::middleware('can:recruitment.manage')->group(function () {
        Route::post('recruitment/openings', [RecruitmentController::class, 'storeOpening'])->name('recruitment.openings.store');
        Route::put('recruitment/openings/{opening}', [RecruitmentController::class, 'updateOpening'])->name('recruitment.openings.update');
        Route::post('recruitment/applicants', [RecruitmentController::class, 'storeApplicant'])->name('recruitment.applicants.store');
        Route::post('recruitment/applicants/{applicant}', [RecruitmentController::class, 'updateApplicant'])->name('recruitment.applicants.update');
        Route::post('recruitment/applicants/{applicant}/move', [RecruitmentController::class, 'moveApplicant'])->name('recruitment.applicants.move');
    });

    // Leave & movement administration (LEV-01..07, POL-09/10).
    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
    Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
    // The authority pays the unpaid days of an approved leave (Joy, 27 Sep 2026).
    Route::post('leave/{leaveRequest}/grant-paid', [LeaveController::class, 'grantPaid'])->name('leave.grant-paid');
    Route::post('leave/quota', [LeaveController::class, 'grantQuota'])->name('leave.quota');

    // Leave-type rules (SmartHR gap G19) — edited from the Leave types tab.
    // ⚠ `leave/types` must be registered before any `leave/{leaveRequest}`
    // pattern would swallow it; kept inside the leave block on purpose.
    Route::post('leave/types', [LeaveTypeController::class, 'store'])->name('leave.types.store');
    Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave.types.update');
    Route::patch('leave/types/{leaveType}/toggle-active', [LeaveTypeController::class, 'toggleActive'])->name('leave.types.toggle-active');

    // Paying claims back is a payroll act, not a self-service one.
    Route::middleware('can:hrm.payroll.manage')->group(function () {
        Route::get('expense-claims', [ExpenseClaimController::class, 'index'])->name('expense-claims.index');
        // The COA mapping behind the categories - the accountant's table.
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::patch('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::post('expense-claims/{claim}/settle', [ExpenseClaimController::class, 'settle'])->name('expense-claims.settle');
        // The reimbursement voucher. Same permission as settling, because it is
        // the paper record of the payment that permission authorises.
        Route::get('expense-claims/{claim}/print', [ExpenseClaimController::class, 'print'])->name('expense-claims.print');
    });

    // ⚠ Approving sits OUTSIDE that permission group on purpose: the workflow
    // stage decides who may act, and it also names Owner as a role. Gating the
    // route on the permission as well would refuse an Owner the stage allows,
    // before the stage was ever consulted. Same shape as loans and leave.
    Route::post('expense-claims/{claim}/approve', [ExpenseClaimController::class, 'approve'])->name('expense-claims.approve');
    Route::post('expense-claims/{claim}/reject', [ExpenseClaimController::class, 'reject'])->name('expense-claims.reject');
    // ⚠ Sending back is a decision on the same stage as approving, so it sits
    // with them rather than behind the payroll permission: the workflow decides
    // who may act, and an Owner the stage allows must not be refused first.
    Route::post('expense-claims/{claim}/send-back', [ExpenseClaimController::class, 'sendBack'])->name('expense-claims.send-back');

    Route::get('holiday-work', [HolidayWorkController::class, 'index'])->name('holiday-work.index');
    Route::post('holiday-work', [HolidayWorkController::class, 'store'])->name('holiday-work.store');
    Route::get('holiday-work/report', [HolidayWorkController::class, 'report'])->name('holiday-work.report');
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

    // Payroll calculation (PAY-01..13). The WFL-02 lifecycle beyond
    // Draft/Calculated arrives in Phase 3c.
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    // The salary register - every payroll month the business has ever run,
    // and one person's own wage record. Registered BEFORE any payroll/{run}
    // pattern could swallow the word `history`.
    Route::get('payroll/history', [PayrollHistoryController::class, 'index'])->name('payroll.history');
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
    // A run approved before accounting was switched on never posted its
    // salary cost. This posts it, through the same accrual the approval uses.
    Route::post('payroll/{run}/post-accrual', [PayrollController::class, 'postAccrual'])->name('payroll.post-accrual');

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
    // 12 Oct 2026: move a confirmed batch, or one payment, to the day the bank
    // actually paid. Owner only; reverses and re-posts, never edits a journal.
    Route::post('disbursement-batches/{batch}/correct-paid-date', [DisbursementController::class, 'correctBatchPaidDate'])->name('batches.correct-paid-date');
    Route::post('payslip-payments/{payment}/correct-paid-date', [DisbursementController::class, 'correctPaymentPaidDate'])->name('payslips.payments.correct-paid-date');
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
    // The same report on screen, as JSON: table, totals, basis note, chart.
    Route::get('reports/{report}/preview', [ReportController::class, 'preview'])->name('reports.preview');

    // RPT-04. Deliberately a separate route and controller from `reports`, not a
    // tab inside it: report generation and dashboard rendering are separate
    // concerns that share only the query layer. No permission middleware here —
    // the registry filters widget by widget, so a user with only attendance
    // rights still gets a dashboard, just a shorter one.
    Route::get('dashboard', [HrmDashboardController::class, 'index'])->name('dashboard');
});
