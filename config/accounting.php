<?php

return [

    /*
    | Refuse a payment the money account cannot cover (finance policy B14).
    |
    | ⚠ ALWAYS ON outside the test suite: production sets no such variable, and
    | the Owner's own switch lives in Finance & Accounts > Finance policy.
    | phpunit.xml turns it off for tests that pay from accounts they never
    | funded; the guard's own tests turn it back on.
    */
    'money_availability' => (bool) env('ACCOUNTING_MONEY_AVAILABILITY', true),

];
