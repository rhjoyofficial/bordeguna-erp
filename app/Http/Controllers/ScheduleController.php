<?php

namespace App\Http\Controllers;

use App\Support\Schedule\ScheduleFeed;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Today's rhythm (14 Oct 2026): the work schedule as Joy drew it, read-only,
 * for every signed-in person. The page works out "today", "now" and "past"
 * itself from the server clock it is handed, so it stays right when it is
 * left open across slots.
 */
class ScheduleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('schedule', [
            'slots' => ScheduleFeed::activeSlots(),
            'server_now' => now(ScheduleFeed::TIMEZONE)->toIso8601String(),
        ]);
    }
}
