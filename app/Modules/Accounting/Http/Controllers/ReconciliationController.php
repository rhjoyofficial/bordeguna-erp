<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `accounting:reconcile`, without the SSH.
 *
 * The command has proven the documents against the ledger since 10 August, but
 * only for whoever can reach a terminal. This is the SAME engine rendered as a
 * screen (C-RSC-1) - the strings are the command's own, so the two can never
 * disagree - run fresh on every load (C-RSC-5), for the business the user is
 * standing in (C-RSC-2).
 */
class ReconciliationController extends Controller
{
    public function index(): Response
    {
        $result = SubledgerReconciliation::reconcileCurrentBusiness();

        return Inertia::render('accounting/reconciliation/index', [
            'checks' => $result['checks'],
            'problems' => $result['problems'],
            'warnings' => $result['warnings'],
            // ⚠⚠ C-RSC-3: zero checks is NOT agreement. The command learned
            // this in the 13 Aug restore drill - 61,500 BDT of stock documents,
            // zero mappings, and a false "everything agrees". The screen makes
            // the same distinction the command now makes.
            'nothingReconciled' => $result['checks'] === 0,
        ]);
    }
}
