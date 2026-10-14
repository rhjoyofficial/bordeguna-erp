<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CustomerStatementLine;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What happened on one supplier's account, and what the business owes now.
 *
 * The payables ageing answers "who do we owe, how overdue"; this answers the
 * supplier's follow-up call: which bills, which payments, in what order.
 * Documents tell the story, the party-tagged ledger checks the balance.
 */
class SupplierStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        // ⚠ C-CST-4: every supplier, including ones with nothing to show.
        // "We owe this supplier nothing" is an answer somebody opened the report
        // to get, and excluding data is how reports lie.
        $suppliers = Supplier::query()->orderBy('name')->get()->map(fn (Supplier $option) => [
            'id' => $option->id,
            'label' => $option->code ? "{$option->name} ({$option->code})" : $option->name,
            'is_active' => $option->is_active,
        ]);

        $supplier = $request->filled('supplier')
            ? Supplier::query()->find($request->integer('supplier'))
            : null;

        if ($supplier === null) {
            return Inertia::render('accounting/supplier-statement/index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'suppliers' => $suppliers,
                'supplier' => null,
                'lines' => [],
                'summary' => null,
            ]);
        }

        $statement = SupplierStatement::for($supplier, $from, $to);

        return Inertia::render('accounting/supplier-statement/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'suppliers' => $suppliers,
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'code' => $supplier->code,
            ],
            'lines' => $statement->lines->map(fn (CustomerStatementLine $line) => [
                'date' => $line->date->toDateString(),
                'kind' => $line->kind,
                'label' => $line->label,
                'reference' => $line->reference,
                // ⚠ Every figure formatted explicitly, signed the way the
                // customer reads it: owes more on the left, owes less on the
                // right.
                'charge' => $line->amount->isDebit() ? $line->amount->format() : null,
                'settlement' => $line->amount->isDebit() ? null : $line->amount->absolute()->format(),
                'balance' => $line->balance->absolute()->format(),
                'balance_side' => $line->balance->isCredit() ? 'credit' : 'debit',
            ])->values(),
            'summary' => [
                'opening' => $statement->opening->absolute()->format(),
                'opening_side' => $statement->opening->isCredit() ? 'credit' : 'debit',
                'charges' => $statement->charges->format(),
                'settlements' => $statement->settlements->absolute()->format(),
                'closing' => $statement->closing()->absolute()->format(),
                'closing_side' => $statement->closing()->isCredit() ? 'credit' : 'debit',
                // ⚠⚠ C-CST-1: shown only when there was something to check
                // against, the same stance as the general ledger.
                'checked' => $statement->hasIndependentCheck(),
                'consistent' => $statement->isConsistent(),
                'ledger_closing' => $statement->ledgerClosing()?->absolute()->format(),
            ],
        ]);
    }

    /**
     * Defaults to the fiscal year to date, like every other accounting report.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();

        $yearStart = $business === null
            ? $today->copy()->startOfYear()
            : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? ReportDates::parse($request, 'from') : $yearStart;
        $to = $request->filled('to') ? ReportDates::parse($request, 'to') : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
