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
    | The date Bor De Guna's configuration takes effect. Schedule assignments
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

];
