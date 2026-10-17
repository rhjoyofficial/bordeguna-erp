<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\CorrectionsReport;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monthly Corrections report (owner's answer 3, PHASE-0-CONTRACT v2 1.14):
 * Owner and Finance (`corrections.report.view`). One business, one calendar
 * month by the moment each thing happened.
 */
class CorrectionsReportController extends Controller
{
    public function index(Request $request, CorrectionsReport $report): Response
    {
        $this->authorize('corrections.report.view');

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'kind' => ['nullable', Rule::in(array_keys(CorrectionsReport::KINDS))],
        ]);

        $month = isset($data['month']) ? Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfDay() : Carbon::today()->startOfMonth();
        $businessId = (int) app(BusinessContext::class)->id();
        $rows = $report->forMonth($businessId, $month, $data['kind'] ?? null);

        return Inertia::render('accounting/corrections/index', [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'kind' => $data['kind'] ?? null,
            'kinds' => CorrectionsReport::KINDS,
            'summary' => $report->summary($businessId, $month),
            'rows' => $rows->all(),
        ]);
    }
}
