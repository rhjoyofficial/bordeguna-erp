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

];
