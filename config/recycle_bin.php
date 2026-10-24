<?php

use App\Models\User;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Models\Attachment;

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

        // ⭐ Corrections wave 3, B11 (24 Oct 2026): things never used, files
        // (library and record attachments), device logins, and logins that
        // never signed in.
        'asset_item' => AssetItem::class,
        'file' => Attachment::class,
        'device_login' => AssetCredential::class,
        'user' => User::class,
    ],
];
