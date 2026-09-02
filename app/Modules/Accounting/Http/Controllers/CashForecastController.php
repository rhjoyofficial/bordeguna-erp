<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CashFlowForecast;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash forecast screen (Module 2, Slice 6): eight weeks ahead, from
 * documents that already exist, with the first negative week flagged.
 * Read-only by construction - a forecast never writes anything.
 */
class CashForecastController extends Controller
{
    public function index(): Response
    {
        $this->authorize('accounting.view');

        return Inertia::render('accounting/cash-forecast', CashFlowForecast::build(8));
    }
}
