<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadence management (Module 4, Slice 3). A cadence is a standing rhythm
 * that writes TASKS; the queue and the overdue digest already carry them.
 * Two triggers, on purpose: a new lead gets its first call, and a customer
 * gone quiet gets one nudge. Paused, never deleted.
 */
class CadenceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('crm/cadences', [
            'cadences' => DB::table('crm_cadences')
                ->where('business_id', app(BusinessContext::class)->id())
                ->orderBy('id')
                ->get()
                ->map(fn ($cadence) => [
                    'id' => $cadence->id,
                    'name' => $cadence->name,
                    'trigger' => $cadence->trigger,
                    'delay_days' => $cadence->delay_days,
                    'task_title' => $cadence->task_title,
                    'is_active' => (bool) $cadence->is_active,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(['lead_created', 'customer_dormant'])],
            'delay_days' => ['required', 'integer', 'min:1', 'max:365'],
            'task_title' => ['required', 'string', 'max:255'],
        ]);

        DB::table('crm_cadences')->insert($data + [
            'business_id' => app(BusinessContext::class)->id(),
            'is_active' => true,
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Cadence is running.');
    }

    public function toggle(int $cadence): RedirectResponse
    {
        $row = DB::table('crm_cadences')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $cadence)->first();
        abort_if($row === null, 404);

        DB::table('crm_cadences')->where('id', $row->id)->update([
            'is_active' => ! $row->is_active, 'updated_at' => now(),
        ]);

        return back();
    }
}
