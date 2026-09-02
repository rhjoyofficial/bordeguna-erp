<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accruals & prepayments (Module 2, Slice 4): the schedules and their
 * progress, with a release button beside the nightly engine. Viewing is
 * accounting.view; creating, releasing and cancelling need journal.post -
 * every one of those acts writes the ledger.
 */
class AmortizationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        return Inertia::render('accounting/amortizations', [
            'schedules' => AmortizationSchedule::query()
                ->with(['sourceAccount:id,code,name', 'targetAccount:id,code,name'])
                ->orderByRaw("status = 'active' desc")
                ->orderByDesc('id')
                ->get()
                ->map(fn (AmortizationSchedule $schedule) => [
                    'id' => $schedule->id,
                    'kind' => $schedule->kind,
                    'name' => $schedule->name,
                    'source' => $schedule->sourceAccount->code.' '.$schedule->sourceAccount->name,
                    'target' => $schedule->targetAccount->code.' '.$schedule->targetAccount->name,
                    'total_amount' => (float) $schedule->total_amount,
                    'months' => $schedule->months,
                    'start_month' => $schedule->start_month->toDateString(),
                    'released_through' => $schedule->released_through?->toDateString(),
                    'released_count' => $schedule->released_through === null ? 0
                        : (int) $schedule->start_month->copy()->startOfMonth()
                            ->diffInMonths($schedule->released_through->copy()->startOfMonth()) + 1,
                    'status' => $schedule->status,
                    'note' => $schedule->note,
                ]),
            'accounts' => Account::query()
                ->where('is_postable', true)->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'can_act' => $request->user()->can('accounting.journal.post'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $data = $request->validate([
            'kind' => ['required', Rule::in(AmortizationSchedule::KINDS)],
            'name' => ['required', 'string', 'max:255'],
            'source_account_id' => ['required', 'different:target_account_id', Rule::exists('accounts', 'id')],
            'target_account_id' => ['required', Rule::exists('accounts', 'id')],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'start_month' => ['required', 'date'],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        AmortizationSchedule::create([
            ...$data,
            'start_month' => date('Y-m-01', strtotime($data['start_month'])),
            'business_id' => app(BusinessContext::class)->id(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Schedule created. Months release on the 1st, or with the release button.');
    }

    public function release(Request $request, ReleaseAmortizations $action): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        $posted = $action->through(now(), $request->user());

        return back()->with('success', $posted === 0
            ? 'Nothing was due - every schedule is already released to date.'
            : $posted.' monthly '.($posted === 1 ? 'release' : 'releases').' posted.');
    }

    public function cancel(Request $request, AmortizationSchedule $schedule): RedirectResponse
    {
        $this->authorize('accounting.journal.post');

        if ($schedule->status !== 'active') {
            return back()->with('error', 'Only an active schedule can be cancelled.');
        }

        // ⚠ Cancelling stops FUTURE months only. What was already released is
        // posted ledger and stays; reversing it is a journal decision a human
        // makes on the journal screen, never a side effect here.
        $schedule->update(['status' => 'cancelled']);

        return back()->with('success', 'Schedule cancelled - released months stay in the ledger.');
    }
}
