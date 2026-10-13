<?php

namespace App\Modules\Accounting\Reporting\Definitions;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Budget vs actual (Module 2, Slice 2; spec §22): the plan beside the truth,
 * account by account, for one fiscal year (July-June).
 *
 * ⚠⚠ THE ACTUAL IS READ FROM journal_lines - the same rows every ledger
 * report prints - never recomputed from documents. A budget report with its
 * own idea of the actual is a second source of truth arguing with the first.
 * Expenses are debit-positive and income credit-negative in the ledger, so
 * the actual is shown as the account type's natural magnitude: spending 6,000
 * reads as 6,000 whichever side it posted on.
 *
 * ⚠⚠ RAW QUERIES, SO THE BUSINESS IS FILTERED BY HAND on every one of them
 * (audit, 13 Oct 2026). Without it the fiscal-year list, the plan and the
 * actual summed every company in the group: one company's report printed the
 * others' budgets and spending as its own.
 *
 * Variance is BUDGET MINUS ACTUAL: positive means room left, negative means
 * overspent (or, for income, short of plan). Registered into the shared
 * engine, so CSV, Excel, PDF, print, the letterhead and the signature row all
 * come from the writers.
 */
class BudgetVsActualReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'accounting_budget_vs_actual';
    }

    public function name(): string
    {
        return 'Budget vs actual';
    }

    public function description(): string
    {
        return 'Every budgeted account for the fiscal year: what was planned, what the ledger actually holds, and the variance. Positive variance is room left; negative is overspent.';
    }

    public function group(): string
    {
        return 'Accounting';
    }

    public function permission(): string
    {
        return 'accounting.view';
    }

    public function parameters(): array
    {
        $years = DB::table('budgets')
            ->where('business_id', self::business())
            ->orderByDesc('fiscal_year')
            ->pluck('fiscal_year')
            ->unique()
            ->map(fn ($year) => ['value' => (int) $year, 'label' => $year.'-'.substr((string) ($year + 1), -2)])
            ->values()
            ->all();

        return [
            ReportParameter::select('fiscal_year', 'Fiscal year', $years, required: true),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('account_code', 'Code', 8),
            ReportColumn::text('account_name', 'Account', 32),
            ReportColumn::money('budget', 'Budget'),
            ReportColumn::money('actual', 'Actual'),
            ReportColumn::money('variance', 'Variance'),
            ReportColumn::text('used', 'Used', 8),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['budget', 'actual', 'variance'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $year = $parameters->int('fiscal_year');

            if ($year === null) {
                return;
            }

            [$from, $to] = self::fiscalBounds($year);

            $business = self::business();

            $budgets = DB::table('budget_lines')
                ->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')
                ->where('budgets.business_id', $business)
                ->where('budgets.fiscal_year', $year)
                ->selectRaw('budget_lines.account_id, SUM(budget_lines.amount) as planned')
                ->groupBy('budget_lines.account_id')
                ->pluck('planned', 'account_id');

            if ($budgets->isEmpty()) {
                return;
            }

            // The ledger's own movement for the same window, signed as stored.
            $actuals = DB::table('journal_lines')
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journals.business_id', $business)
                ->whereBetween('journals.journal_date', [$from, $to])
                ->whereIn('journal_lines.account_id', $budgets->keys())
                ->selectRaw('journal_lines.account_id, SUM(journal_lines.amount_minor) / 100 as moved')
                ->groupBy('journal_lines.account_id')
                ->pluck('moved', 'account_id');

            $accounts = Account::whereIn('id', $budgets->keys())->orderBy('code')->get();

            foreach ($accounts as $account) {
                $budget = round((float) $budgets[$account->id], 2);
                // Natural magnitude: expense debits are positive already;
                // income credits are negative, so flip income's sign.
                $signed = (float) ($actuals[$account->id] ?? 0);
                $actual = round($account->type->value === 'income' ? -$signed : $signed, 2);
                $variance = round($budget - $actual, 2);

                yield [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'budget' => $budget,
                    'actual' => $actual,
                    'variance' => $variance,
                    'used' => $budget != 0.0 ? round($actual / $budget * 100).'%' : '-',
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        $year = $parameters->int('fiscal_year');

        if ($year === null) {
            return 0;
        }

        return DB::table('budget_lines')
            ->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')
            ->where('budgets.business_id', self::business())
            ->where('budgets.fiscal_year', $year)
            ->distinct('budget_lines.account_id')
            ->count('budget_lines.account_id');
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $year = $parameters->int('fiscal_year');

        return new ReportMeta(
            title: 'Budget vs actual',
            subtitle: $year === null ? null : sprintf('Fiscal year %d-%s (July to June)', $year, substr((string) ($year + 1), -2)),
            note: 'The actual is the ledger\'s own movement for the window - the same rows the general ledger prints. Positive variance is room left; negative is overspent, or income short of plan.',
        );
    }

    private static function business(): int
    {
        return (int) app(BusinessContext::class)->id();
    }

    /** @return array{0: string, 1: string} */
    public static function fiscalBounds(int $year): array
    {
        return [$year.'-07-01', ($year + 1).'-06-30'];
    }
}
