<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The period close screen (Module 2, Slice 1).
 *
 * ⚠⚠ The ENGINE predates this screen by a month: closeThrough(), unlock() and
 * relock() have sat on AccountingPeriod since Accounting Phase 1.2, enforced
 * by assertOpenFor at every posting choke point - and nothing called them.
 * The tenth unreachable engine; this screen is the missing reach.
 *
 * Viewing needs accounting.view; ACTING needs accounting.chart.manage - the
 * strongest accounting grant, because closing the books changes what every
 * other permission may do. A dedicated accounting.period.close permission can
 * split off later with one seeder row; borrowing chart.manage today beats
 * shipping a grant nobody has.
 */
class AccountingPeriodController extends Controller
{
    public function show(Request $request): Response
    {
        $this->authorize('accounting.view');

        $lock = AccountingPeriod::current();

        return Inertia::render('accounting/period-close', [
            'boundary' => $lock?->closed_through?->toDateString(),
            'closed_by' => $lock?->closed_by ? DB::table('users')->where('id', $lock->closed_by)->value('name') : null,
            'note' => $lock?->note,
            'is_unlocked' => (bool) ($lock?->is_unlocked ?? false),
            'unlock_note' => $lock?->unlock_note,
            'unlocked_by' => $lock?->unlocked_by ? DB::table('users')->where('id', $lock->unlocked_by)->value('name') : null,
            'can_close' => $request->user()->can('accounting.chart.manage'),
            'checklist' => $this->checklist(),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $this->authorize('accounting.chart.manage');

        $data = $request->validate([
            // You close what has happened, never what has not.
            'closed_through' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // ⚠⚠ Closing through an EARLIER date reopens every month after it, with
        // no reason recorded - a quieter unlock than Unlock itself. Moving the
        // boundary back needs the books unlocked first, which takes a reason.
        $lock = AccountingPeriod::current();
        $through = Carbon::parse($data['closed_through'])->startOfDay();

        if ($lock !== null && ! $lock->is_unlocked && $through->lt($lock->closed_through->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'closed_through' => 'The books are closed through '.$lock->closed_through->format('d M Y').'. Closing through an earlier date would reopen the months after it: unlock the period first, with the reason, then close again.',
            ]);
        }

        AccountingPeriod::closeThrough(
            Carbon::parse($data['closed_through']),
            $request->user(),
            $data['note'] ?? null,
        );

        return back()->with('success', 'Books closed through '.$data['closed_through'].'.');
    }

    public function unlock(Request $request): RedirectResponse
    {
        $this->authorize('accounting.chart.manage');

        $lock = AccountingPeriod::current();

        if ($lock === null) {
            return back()->with('error', 'The books have never been closed - there is nothing to unlock.');
        }

        // ⚠ The reason is REQUIRED. An unlock is an event with a reason
        // attached, never a setting somebody flips - a quiet boolean is how a
        // temporary exception becomes permanent.
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $lock->unlock($request->user(), $data['reason']);

        return back()->with('success', 'Period unlocked. Close again when the correction is done.');
    }

    /**
     * The pre-close facts (spec §65), read-only and cheap: each row is a
     * question the accountant should answer before locking the month. The
     * screen never blocks on them - it informs; the human decides.
     *
     * @return array<int, array{label: string, value: string, warns: bool}>
     */
    private function checklist(): array
    {
        $balance = fn (string $slug) => (float) DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.system_slug', $slug)
            ->sum('amount_minor') / 100;

        $salariesPayable = $balance('salaries_payable');
        $grni = $balance('goods_received_not_invoiced');

        $latestJournal = DB::table('journals')->max('journal_date');

        $needsReview = DB::table('daily_attendances')
            ->where('attendance_date', '>=', now()->subDays(35)->toDateString())
            ->where('flags', 'like', '%needs_review%')
            ->count();

        return [
            [
                'label' => 'Latest journal in the books',
                'value' => $latestJournal ? (string) $latestJournal : 'none yet',
                'warns' => false,
            ],
            [
                'label' => 'Salaries Payable balance',
                'value' => number_format($salariesPayable, 2),
                'warns' => abs($salariesPayable) >= 0.01,
            ],
            [
                'label' => 'Goods received, not yet billed (GRNI)',
                'value' => number_format($grni, 2),
                'warns' => abs($grni) >= 0.01,
            ],
            [
                'label' => 'Attendance days still needing review (35-day window)',
                'value' => (string) $needsReview,
                'warns' => $needsReview > 0,
            ],
        ];
    }
}
