<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessHealthSignals;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Business Health feed (Module 7, Slice 1): the morning walk around the
 * shop, done by the system.
 *
 * Every signal is computed FRESH from the module that owns it and links to
 * the screen where the fix lives. The feed invents no state and stores
 * nothing - there is no flag to go stale, and no signal can disagree with
 * its own module's screen. A viewer sees only the signals their permissions
 * let them act on; a broken signal source drops that signal rather than the
 * page (a health check that itself falls ill helps nobody).
 *
 * The signals themselves live in BusinessHealthSignals (11 Oct 2026), shared
 * with the Owner dashboard's health widget.
 */
class BusinessHealthController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('health', [
            'signals' => BusinessHealthSignals::for($request->user()),
        ]);
    }
}
