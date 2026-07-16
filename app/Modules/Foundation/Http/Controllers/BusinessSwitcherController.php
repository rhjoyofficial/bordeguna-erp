<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSwitcherController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $businesses = $user->is_super_admin
            ? Business::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug'])
            : $user->businesses()->where('is_active', true)->orderBy('name')->get(['businesses.id', 'businesses.name', 'businesses.slug']);

        return Inertia::render('business-switcher', [
            'businesses' => $businesses,
            'current_business_id' => $request->session()->get('current_business_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
        ]);

        $user = $request->user();
        $business = Business::where('is_active', true)->findOrFail($data['business_id']);

        $allowed = $user->is_super_admin
            || $user->businesses()->where('businesses.id', $business->id)->exists();

        abort_unless($allowed, 403);

        $request->session()->put('current_business_id', $business->id);

        return redirect()->route('dashboard');
    }
}
