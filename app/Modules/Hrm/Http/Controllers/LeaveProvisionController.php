<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Hrm\Actions\PostLeaveProvision;
use App\Modules\Hrm\Actions\RunLeaveProvision;
use App\Modules\Hrm\Models\LeaveProvisionRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The leave encashment provision screen.
 *
 * ⚠ The preview and the posting run the SAME computation (`plan()`), so what
 * is reviewed here is exactly what lands in the ledger - the year-end
 * processor's doctrine, and the reason nobody has to trust this screen
 * separately from the engine.
 */
class LeaveProvisionController extends Controller
{
    public function index(Request $request, RunLeaveProvision $action, PostLeaveProvision $posting): Response
    {
        $this->authorize('hrm.payroll.manage');

        $on = $request->string('valued_on')->toString() ?: null;
        $plan = $action->plan($on);

        $runs = LeaveProvisionRun::query()
            ->with('runner:id,name')
            ->orderByDesc('valued_on')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return Inertia::render('hrm/leave-provision/index', [
            'plan' => $plan,
            'valuedOn' => $plan['valued_on'],
            // What the ledger carries today, so the delta on the screen is the
            // same subtraction the posting will make.
            'currentBalance' => number_format($posting->currentBalance($plan['valued_on']), 2, '.', ''),
            'runs' => $runs,
        ]);
    }

    public function store(Request $request, RunLeaveProvision $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'valued_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $run = $action->commit(
            runBy: $request->user(),
            on: $data['valued_on'] ?? null,
            note: $data['note'] ?? null,
        );

        return redirect()->route('hrm.leave-provision.show', $run)
            ->with('success', 'Leave provision recorded.');
    }

    public function show(LeaveProvisionRun $leaveProvision): Response
    {
        $this->authorize('hrm.payroll.manage');

        $leaveProvision->load('runner:id,name');

        $journal = Journal::query()
            ->where('source_type', $leaveProvision->getMorphClass())
            ->where('source_id', $leaveProvision->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        return Inertia::render('hrm/leave-provision/show', [
            'run' => $leaveProvision,
            'journal' => $journal,
        ]);
    }
}
