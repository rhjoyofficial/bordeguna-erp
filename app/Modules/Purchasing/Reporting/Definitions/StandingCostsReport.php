<?php

namespace App\Modules\Purchasing\Reporting\Definitions;

use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringOccurrenceStatus;
use App\Modules\Purchasing\Models\RecurringExpense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * What this business is committed to every month whether anybody remembers it
 * or not — hosting, the API subscriptions, the domains.
 *
 * ⚠⚠ **THIS REPORT IS A COMMITMENT, NOT A COST.** Every other report in the
 * system reads money that has already moved. This one reads a schedule, and a
 * schedule is a prediction: the row for October is what October is *expected*
 * to take, and it will still say so if the card is declined. That is why the
 * lock note says it in plain words on the printed face, where the three people
 * signing it will read it — a page that looked like an expense register but
 * quietly totalled forecasts would be the most convincing wrong number in the
 * system.
 *
 * ⚠ **The amount column is deliberately not totalled.** Adding 4,900 a month to
 * 1,200 a year produces a figure that is neither. `Per month` and `Per year`
 * are the two columns that can honestly be added up, because every frequency
 * has already been levelled to them.
 */
class StandingCostsReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'purchasing_standing_costs';
    }

    public function name(): string
    {
        return 'Standing costs and subscriptions';
    }

    public function description(): string
    {
        return 'Every subscription and monthly bill the business is committed to, what each costs per month and per year, and when the next one falls due. A commitment schedule, not a record of payments.';
    }

    public function group(): string
    {
        return 'Purchases';
    }

    public function permission(): string
    {
        return 'purchasing.reports.view';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::select('state', 'Show', [
                ['value' => 'active', 'label' => 'Running now'],
                ['value' => 'all', 'label' => 'All, paused and ended included'],
            ], default: 'active'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('name', 'What', 30),
            ReportColumn::text('supplier', 'Supplier', 22),
            ReportColumn::text('account', 'Charged to', 28),
            ReportColumn::text('paid_from', 'Usually paid from', 20),
            ReportColumn::text('frequency', 'How often', 16),
            ReportColumn::text('due_day', 'Due on', 20),
            ReportColumn::money('amount', 'Amount'),
            ReportColumn::money('per_month', 'Per month'),
            ReportColumn::money('per_year', 'Per year'),
            ReportColumn::date('next_due', 'Next due'),
            ReportColumn::text('status', 'Status', 12),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['per_month', 'per_year'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $schedule) {
                yield [
                    'name' => $schedule->name,
                    'supplier' => $schedule->supplier?->name,
                    'account' => $schedule->expenseAccount?->label(),
                    'paid_from' => $schedule->moneyAccount?->name ?? 'Decided each time',
                    'frequency' => $schedule->frequency->label(),
                    // ⚠ 31 means "the last day", and February is why. Printing
                    // "day 31" on a page somebody files would be a small lie
                    // that the screen has already stopped telling.
                    'due_day' => $schedule->day_of_month >= 29
                        ? 'Last day of the month'
                        : 'Day '.$schedule->day_of_month,
                    'amount' => round((float) $schedule->amount, 2),
                    'per_month' => $schedule->monthlyRunRate(),
                    'per_year' => $schedule->annualCommitment(),
                    'next_due' => $schedule->next_due,
                    'status' => $schedule->status->label()
                        .($schedule->amount_varies ? ' (amount varies)' : ''),
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Standing costs and subscriptions',
            subtitle: 'As at '.now()->format('d M Y'),
            periodLocked: null,
            lockNote: 'Every figure here is what a schedule EXPECTS to cost. Nothing on this page has been paid by being on it. What was actually paid is on the expense register.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        // ⚠ The next open occurrence, as a correlated subquery rather than a
        // relation read per row. A settled month must not answer "next due" —
        // September's bill, once recorded, is behind us.
        $nextDue = DB::raw('(select min(due_on) from recurring_expense_occurrences'
            .' where recurring_expense_occurrences.recurring_expense_id = recurring_expenses.id'
            ." and recurring_expense_occurrences.status = '".RecurringOccurrenceStatus::Due->value."') as next_due");

        return RecurringExpense::query()
            ->with(['supplier:id,name', 'expenseAccount:id,code,name', 'moneyAccount:id,name'])
            ->when(
                ($parameters->string('state') ?? 'active') === 'active',
                fn (Builder $q) => $q->where('status', RecurringExpenseStatus::Active),
            )
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->select(['recurring_expenses.*', $nextDue]);
    }
}
