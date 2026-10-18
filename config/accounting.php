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

    /*
    | Purchasing approvals (ruling P7, Joy 18 Oct 2026): a bill, supplier
    | payment, advance or refund at or above the Finance policy limit, or with
    | an exception, waits for a second person.
    |
    | ⚠ ALWAYS ON outside the test suite, the money_availability pattern:
    | production sets no such variable, and the Owner's own switches live in
    | Finance policy. phpunit.xml turns it off for the hundreds of tests that
    | record a first bill for a brand-new supplier; the gate's own tests turn
    | it back on.
    */
    'purchasing_approvals' => (bool) env('ACCOUNTING_PURCHASING_APPROVALS', true),

    /*
    | The ERP cutover: the first day the books are kept here, the day the
    | accountant's opening trial balance is struck for (1 September 2026, the
    | day the business went live). An OPENING fixed asset is valued at this
    | date and depreciated by the ERP from it (FEA-003/004, owner decision
    | 14 Oct 2026). Read by App\Modules\Accounting\Support\Cutover.
    */
    'cutover_date' => env('ACCOUNTING_CUTOVER_DATE', '2026-08-24'),

];
