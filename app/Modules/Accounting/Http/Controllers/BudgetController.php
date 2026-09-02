<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Reporting\Definitions\BudgetVsActualReport;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The budget screen (Module 2, Slice 2): a grid of account x month for one
 * fiscal year (July-June). Viewing is accounting.view; SAVING is
 * chart.manage, the same split as the period close - a plan constrains what
 * every report says about the year, so setting it is a management act.
 *
 * ⚠ The save UPSERTS by (budget, account, month) and deletes nothing: a cell
 * emptied on screen is sent as amount 0, never as an absent row, so a partial
 * save can never silently wipe the months it did not carry.
 */
class BudgetController extends Controller
{
    public function show(Request $request): Response
    {
        $this->authorize('accounting.view');

        // Fiscal year starts July: before July you are still in last year's.
        $defaultYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $year = (int) $request->query('fiscal_year', (string) $defaultYear);

        $budget = DB::table('budgets')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('fiscal_year', $year)
            ->first();

        $lines = $budget === null ? collect() : DB::table('budget_lines')
            ->where('budget_id', $budget->id)
            ->get(['account_id', 'month', 'amount']);

        return Inertia::render('accounting/budgets', [
            'fiscal_year' => $year,
            'months' => collect(range(0, 11))
                ->map(fn (int $offset) => now()->setDate($year, 7, 1)->addMonths($offset)->format('Y-m-01'))
                ->all(),
            'accounts' => Account::query()
                ->whereIn('type', ['expense', 'income'])
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'lines' => $lines,
            'note' => $budget->note ?? null,
            'can_edit' => $request->user()->can('accounting.chart.manage'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->authorize('accounting.chart.manage');

        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['array'],
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')],
            'lines.*.month' => ['required', 'date_format:Y-m-d'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $businessId = app(BusinessContext::class)->id();

        DB::transaction(function () use ($data, $businessId, $request) {
            $budgetId = DB::table('budgets')->where('business_id', $businessId)
                ->where('fiscal_year', $data['fiscal_year'])->value('id');

            if ($budgetId === null) {
                $budgetId = DB::table('budgets')->insertGetId([
                    'business_id' => $businessId,
                    'fiscal_year' => $data['fiscal_year'],
                    'note' => $data['note'] ?? null,
                    'created_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (array_key_exists('note', $data)) {
                DB::table('budgets')->where('id', $budgetId)->update(['note' => $data['note'], 'updated_at' => now()]);
            }

            foreach ($data['lines'] ?? [] as $line) {
                DB::table('budget_lines')->updateOrInsert(
                    [
                        'budget_id' => $budgetId,
                        'account_id' => $line['account_id'],
                        'month' => $line['month'],
                    ],
                    ['amount' => $line['amount'], 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        return back()->with('success', 'Budget saved for '.$data['fiscal_year'].'-'.substr((string) ($data['fiscal_year'] + 1), -2).'.');
    }
}
