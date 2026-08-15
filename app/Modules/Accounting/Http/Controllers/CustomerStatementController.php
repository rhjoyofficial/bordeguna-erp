<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\CustomerStatementLine;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What happened on one customer's account, and what they owe now.
 *
 * The receivables ageing answers "who owes us, how overdue"; this answers the
 * follow-up a dealer asks on the phone: which invoices, which payments, in what
 * order. Documents tell the story, the party-tagged ledger checks the balance
 * (C-CST-1), and a printable statement for SENDING is deferred to Joy (C-CST-6).
 */
class CustomerStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());

        [$from, $to] = $this->window($request, $business);

        // ⚠ C-CST-4: every customer, including ones with nothing to show.
        // "This customer owes nothing" is an answer somebody opened the report
        // to get, and excluding data is how reports lie.
        $customers = Customer::query()->orderBy('name')->get()->map(fn (Customer $option) => [
            'id' => $option->id,
            'label' => $option->code ? "{$option->name} ({$option->code})" : $option->name,
            'is_active' => $option->is_active,
        ]);

        $customer = $request->filled('customer')
            ? Customer::query()->find($request->integer('customer'))
            : null;

        if ($customer === null) {
            return Inertia::render('accounting/customer-statement/index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'customers' => $customers,
                'customer' => null,
                'lines' => [],
                'summary' => null,
            ]);
        }

        $statement = CustomerStatement::for($customer, $from, $to);

        return Inertia::render('accounting/customer-statement/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'customers' => $customers,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
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

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $yearStart;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
