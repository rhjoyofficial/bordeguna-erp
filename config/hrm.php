<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting (W3)
    |--------------------------------------------------------------------------
    |
    | Correction C10e: a report is rendered directly when it is small enough to
    | serve inside a request, and handed to the queue when it is not. The
    | threshold is configuration rather than code because the right number
    | depends on the host, not on the rules — the same report is comfortable on
    | a VPS and marginal on shared hosting.
    |
    | It is an estimate of ROWS, taken before any row is produced.
    |
    */

    'reporting' => [
        'queue_threshold' => (int) env('HRM_REPORT_QUEUE_THRESHOLD', 5000),

        /*
        |----------------------------------------------------------------------
        | Payroll variance threshold (Correction C10c)
        |----------------------------------------------------------------------
        |
        | The smallest movement worth printing on the variance report, in taka.
        | Seeded at 0 — show everything — because hiding rows is a decision the
        | business should take deliberately rather than inherit from a default.
        |
        | It is data rather than code precisely so that raising it is a setting
        | change and not a release: a payroll manager chasing a month of noise
        | needs the answer that afternoon.
        |
        */
        'variance_threshold' => (float) env('HRM_REPORT_VARIANCE_THRESHOLD', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard (RPT-04)
    |--------------------------------------------------------------------------
    |
    | Correction C11e: a dashboard is the first page everybody opens and several
    | of its tiles aggregate a month of attendance, on shared hosting. So a
    | widget's figure may be cached — but each widget declares its own tolerance,
    | because the right answer differs per tile and the widget is where the data
    | is understood. This is only the default they fall back to; a widget that
    | must be live (pending approvals) returns 0 and is computed every time.
    |
    | ⚠ The cache key includes the resolved ReportScope, not merely the filters.
    | Two users with different department scopes must never share an entry —
    | that would undo C10d without anybody editing C10d.
    |
    */

    'dashboard' => [
        'cache_seconds' => (int) env('HRM_DASHBOARD_CACHE_SECONDS', 60),
    ],
    /*
    |--------------------------------------------------------------------------
    | Go-live date
    |--------------------------------------------------------------------------
    |
    | The date Bor de Guna's configuration takes effect. Schedule assignments
    | are effective-dated, and the effective date decides which shift and which
    | calendar a given day is judged against — so this is not cosmetic.
    |
    | Left null until the date is agreed, in which case provisioning uses today,
    | which is harmless while no attendance exists. ⚠ Recommended: pick a 24th,
    | so the first system payroll covers a whole 24th-to-23rd period and the
    | parallel run compares like with like.
    |
    */

    'go_live_date' => env('HRM_GO_LIVE_DATE'),

    /*
    |--------------------------------------------------------------------------
    | Early leave (Joy's HRM queue, 27 Oct 2026)
    |--------------------------------------------------------------------------
    |
    | An employee asks to leave early on a day; a manager or the owner approves.
    | Approved means no deduction for that early departure and the day is
    | finalised. Read through App\Modules\Hrm\Support\EarlyLeaveRules.
    |
    | `unapproved_departure` is what an early departure WITHOUT an approved
    | request does. Joy left it open; option A was recommended and applied:
    | `half_day_rule`, exactly today's rule (flagged for review, and a half day
    | when the payable hours fall to the half-day line). It is the only value
    | the engine knows; anything else is refused rather than silently ignored.
    |
    | `monthly_request_cap` limits how many early leave requests one person may
    | file in a payroll cycle (24th to 23rd, since 27 Oct 2026), counting those
    | still waiting. Empty = no cap, which is the decision for now.
    |
    | `reminder_after_days` is how long a request may wait before its approvers
    | are reminded (once). `cycle_end_warning_days` is how many days before the
    | payroll cycle ends HR is sent the list of days still open (3 = the 20th
    | for the 24-23 cycle).
    |
    */

    'early_leave' => [
        'unapproved_departure' => env('HRM_EARLY_LEAVE_UNAPPROVED_DEPARTURE', 'half_day_rule'),
        'monthly_request_cap' => env('HRM_EARLY_LEAVE_MONTHLY_CAP') === null || env('HRM_EARLY_LEAVE_MONTHLY_CAP') === ''
            ? null
            : (int) env('HRM_EARLY_LEAVE_MONTHLY_CAP'),
        'reminder_after_days' => (int) env('HRM_EARLY_LEAVE_REMINDER_AFTER_DAYS', 2),
        'cycle_end_warning_days' => (int) env('HRM_ATTENDANCE_CYCLE_END_WARNING_DAYS', 3),
    ],

];
