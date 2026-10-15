<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The standing costs as numbers: what is due, and the run rate.
 *
 * ⭐ Purchases redesign (15 Oct 2026): the Recurring expenses screen and the
 * Purchases overview read the SAME list, from here, so the overview can never
 * say "two due this week" while the screen says three. Pulled out of
 * RecurringExpenseController, which still calls it.
 *
 * ⛔ Nothing here posts. The rows predict; a person records.
 */
class StandingCosts
{
    /**
     * Occurrences due within `$withinDays`, overdue FIRST and never hidden,
     * each carrying the prefill the expense dialog opens with.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function due(int $withinDays = 30, ?int $limit = null): Collection
    {
        $today = CarbonImmutable::today();

        return RecurringExpenseOccurrence::with(['recurringExpense.supplier:id,name', 'recurringExpense.expenseAccount:id,code,name'])
            ->due()
            ->where('due_on', '<=', $today->addDays($withinDays)->toDateString())
            ->orderBy('due_on')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (RecurringExpenseOccurrence $o) => [
                'id' => $o->id,
                'name' => $o->recurringExpense?->name,
                'supplier' => $o->recurringExpense?->supplier?->name,
                'account' => $o->recurringExpense?->expenseAccount?->label(),
                'period_label' => $o->period_label,
                'due_on' => $o->due_on->toDateString(),
                'expected_amount' => (float) $o->expected_amount,
                'amount_varies' => (bool) $o->recurringExpense?->amount_varies,
                'days_away' => (int) $today->diffInDays($o->due_on, false),
                // Everything the expense dialog needs, so pressing Record
                // opens it already filled in.
                'prefill' => [
                    'spent_on' => $o->due_on->toDateString(),
                    'money_account_id' => $o->recurringExpense?->money_account_id,
                    'supplier_id' => $o->recurringExpense?->supplier_id,
                    'reference' => $o->recurringExpense?->referenceFor(CarbonImmutable::parse($o->due_on)),
                    'expense_account_id' => $o->recurringExpense?->expense_account_id,
                    'amount' => (float) $o->expected_amount,
                    'description' => $o->recurringExpense?->name.' '.$o->period_label,
                ],
            ])
            ->values();
    }

    /**
     * The active schedules' run rate, levelled to a month and to a year.
     *
     * @param  Collection<int, RecurringExpense>|null  $schedules  Every schedule, when the caller already loaded them.
     * @return array{monthly: float, annual: float, count: int}
     */
    public static function totals(?Collection $schedules = null): array
    {
        $active = ($schedules ?? RecurringExpense::query()->get())->where('status', RecurringExpenseStatus::Active);

        return [
            'monthly' => round($active->sum(fn (RecurringExpense $s) => $s->monthlyRunRate()), 2),
            'annual' => round($active->sum(fn (RecurringExpense $s) => $s->annualCommitment()), 2),
            'count' => $active->count(),
        ];
    }
}
