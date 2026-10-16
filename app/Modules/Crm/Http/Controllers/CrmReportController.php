<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Support\CrmReportQueries;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the CRM can honestly tell management.
 *
 * ⚠⚠ NOTHING HERE IS CACHED OR STORED. Every figure is derived on read, so no
 * report can drift from the records it came from. That is the same rule the
 * ledger follows - a balance is a question asked of the journal lines, never a
 * column somebody keeps in step.
 *
 * ⛔ NOT A SECOND REPORT FRAMEWORK. `App\Modules\Hrm\Reporting` already provides
 * parameterised, exportable, queued reports; promoting its generic half to
 * Foundation is a 59-file extraction with real HRM coupling and is deliberately
 * NOT done here. See CRM-REPORTING-FOUNDATIONS.md §1.
 *
 * The queries live in CrmReportQueries since round 3 (16 Oct 2026), shared
 * with the CRM Overview.
 */
class CrmReportController extends Controller
{
    public function index(Request $request, CrmReportQueries $queries): Response
    {
        return Inertia::render('crm/reports', [
            'funnel' => $queries->funnel(),
            'sources' => $queries->sourcePerformance(),
            'demand' => $queries->unmetDemand(),
            'followUps' => $queries->followUpLoad($request->user()),
            'canSeeAllTasks' => $request->user()?->can('crm.tasks.view.all') ?? false,
        ]);
    }
}
