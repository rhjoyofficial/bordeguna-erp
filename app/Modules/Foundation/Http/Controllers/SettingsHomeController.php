<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings home (Joy, 15 Oct 2026): every area this person may set up,
 * as grouped cards with a search box, behind ONE sidebar link.
 *
 * ⚠ Nothing is passed in: the cards are built in the browser from the shared
 * permission flags (settings-sections.ts), the same flags every tab and every
 * old sidebar link read, so the home can never show a screen the tab row
 * would hide. The one number it needs, the failed-jobs count, is a shared
 * prop gated on its own permission (HandleInertiaRequests).
 *
 * Open to anybody signed in: everybody has at least My account.
 */
class SettingsHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/home');
    }
}
