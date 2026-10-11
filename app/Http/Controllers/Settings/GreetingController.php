<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Preferences\BismillahGreeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One's own Bismillah greeting switch (Settings > Appearance). Auth only, no
 * permission: a person sets their own and can reach nobody else's row.
 */
class GreetingController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        BismillahGreeting::save($request->user(), (bool) $data['enabled']);

        return back();
    }
}
