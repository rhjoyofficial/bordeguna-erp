<?php

use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;

/*
|--------------------------------------------------------------------------
| The Recycle bin (edit, delete and correct, PHASE-0-CONTRACT v2, 1.5)
|--------------------------------------------------------------------------
|
| The models whose drafts and never-used rows may go to the bin, keyed by a
| stable name used in URLs. Each one uses the Recyclable trait and has the
| deleted_at, deleted_by and delete_reason columns.
|
| ⛔ EMPTY IN PHASE 0. Models opt in from Phase 4, each with its own migration.
|
| ⛔⛔ NEVER: journals, journal lines, stock movements, payslip lines, leave
| ledger rows, raw punches, correction links, correction requests, closed-month
| exceptions, duplicate acknowledgements, or any posted document. A structural
| test (tests/Feature/Corrections/StructuralTest.php) refuses them.
|
| ⛔ No purge, ever (FINAL-PLAN section 9, answer 1): there is no prune job and
| no "Delete forever". Only the Owner restores.
|
*/

return [
    'models' => [
        // 'department' => App\Modules\Foundation\Models\Department::class,  (Phase 4)
        // Corrections wave 3 (24 Oct 2026): an unused DRAFT employee only.
        'employee' => Employee::class,
        // ⭐ B13 (24 Oct 2026): a customer or supplier never used.
        'customer' => Customer::class,
        'supplier' => Supplier::class,
    ],
];
