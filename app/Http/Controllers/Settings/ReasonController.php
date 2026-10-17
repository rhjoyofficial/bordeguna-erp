<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the per-business reason pick-lists consumed by stock adjustments
 * and transfers (see Reason model). Reasons are deactivated, never deleted —
 * historical stock movements/requests keep referencing the reason_id they
 * were recorded with.
 */
class ReasonController extends Controller
{
    private const CONTEXTS = [
        'inventory.stock_adjustment' => 'Stock adjustment',
        'inventory.stock_transfer' => 'Stock transfer',
        // Edit, delete and correct, Phase 0 (17 Oct 2026): one list per verb.
        'correction' => 'Correct a record',
        'reversal' => 'Reverse a record',
        'cancellation' => 'Cancel a record',
        'withdrawal' => 'Withdraw a request',
        'deletion' => 'Delete a draft',
        'deactivation' => 'Switch a record off',
        'disposal' => 'Dispose of an asset',
        'restore' => 'Restore from the Recycle bin',
        'fix_details' => 'Fix details',
    ];

    public function index(): Response
    {
        $this->authorize('inventory.catalog.manage');

        return Inertia::render('settings/reasons', [
            'reasons' => Reason::orderBy('context')->orderBy('name')->get(),
            'contexts' => self::CONTEXTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.catalog.manage');

        $data = $request->validate([
            'context' => ['required', 'string', 'in:' . implode(',', array_keys(self::CONTEXTS))],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('reasons')->where('business_id', app(BusinessContext::class)->id())->where('context', $request->input('context')),
            ],
        ]);

        Reason::create($data);

        return back()->with('success', 'Reason created.');
    }

    public function update(Request $request, Reason $reason): RedirectResponse
    {
        $this->authorize('inventory.catalog.manage');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('reasons')
                    ->where('business_id', app(BusinessContext::class)->id())
                    ->where('context', $reason->context)
                    ->ignore($reason),
            ],
        ]);

        $reason->update($data);

        return back()->with('success', 'Reason updated.');
    }

    public function toggleActive(Reason $reason): RedirectResponse
    {
        $this->authorize('inventory.catalog.manage');

        $reason->update(['is_active' => ! $reason->is_active]);

        return back()->with('success', $reason->is_active ? 'Reason reactivated.' : 'Reason deactivated.');
    }
}
