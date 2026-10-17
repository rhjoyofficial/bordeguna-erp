<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Cash position at an Accounts address (F34, practice run 17 Oct 2026).
 *
 * Every report lives in one registry and is served by one screen at
 * /hrm/reports/{key}, so Accounts > Bank & cash > Cash position used to open
 * under /hrm/. This serves the SAME screen from the SAME registry entry at
 * /accounting/reports/cash-position: one report, one permission check
 * (the registry's accounting.view, a 404 for anybody else), one dataset. The
 * old address keeps working because it is the registry's own route.
 */
class CashPositionController extends Controller
{
    public const ADDRESS = '/accounting/reports/cash-position';

    public function show(Request $request, ReportController $reports): Response
    {
        return $reports->show($request, 'cash_position')->with('address', self::ADDRESS);
    }
}
