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
    ],

];
