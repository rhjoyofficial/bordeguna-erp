<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyStatement;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ The combined party statement (decision D-F, 18 Oct 2026): both accounts
 * of one firm that is our supplier and our customer, with a running net, for
 * a date range. Printable. Reached from either page, and from Purchases.
 *
 * ⚠ Read by whoever reads both suppliers and customers (the route gate).
 */
class PartyStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());
        [$from, $to] = $this->window($request, $business);

        $parties = Customer::query()->whereNotNull('supplier_id')->with('alsoSupplier:id,name')->orderBy('name')->get()
            ->filter(fn (Customer $c) => $c->alsoSupplier !== null)
            ->map(fn (Customer $c) => ['supplier_id' => $c->supplier_id, 'label' => $c->alsoSupplier->name === $c->name ? $c->name : "{$c->alsoSupplier->name} / {$c->name}"])
            ->values();

        // Either side opens the same statement: ?supplier= or ?customer=.
        $supplier = $request->filled('supplier') ? Supplier::query()->find($request->integer('supplier')) : null;
        $customer = $supplier !== null
            ? Customer::query()->where('supplier_id', $supplier->id)->first()
            : ($request->filled('customer') ? Customer::query()->whereNotNull('supplier_id')->find($request->integer('customer')) : null);
        $supplier ??= $customer?->alsoSupplier;

        $base = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'parties' => $parties,
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ];

        if ($supplier === null || $customer === null) {
            return Inertia::render('accounting/party-statement/index', $base + ['party' => null, 'lines' => [], 'summary' => null]);
        }

        $statement = PartyStatement::for($supplier, $customer, $from, $to);
        $signed = fn (Money $net) => ['amount' => $net->absolute()->format(), 'side' => $net->minor > 0 ? 'us' : ($net->minor < 0 ? 'them' : 'even')];

        return Inertia::render('accounting/party-statement/index', $base + [
            'party' => [
                'supplier_id' => $supplier->id,
                'supplier' => $supplier->name,
                'customer_id' => $customer->id,
                'customer' => $customer->name,
            ],
            'lines' => $statement->lines->map(fn (array $line) => [
                'date' => $line['date']->toDateString(),
                'side' => $line['side'],
                'kind' => $line['kind'],
                'label' => $line['label'],
                'reference' => $line['reference'],
                // The amount as its own account reads it, and its effect on the net.
                'they_owe_change' => $line['side'] === 'customer' ? $this->signedFormat($line['amount']) : null,
                'we_owe_change' => $line['side'] === 'supplier' ? $this->signedFormat($line['amount']) : null,
                // ⭐ 20 Oct 2026: our advance with them (1160), the third part of the net.
                'advance_change' => $line['side'] === 'advance' ? $this->signedFormat($line['amount']) : null,
                'net' => $signed($line['net']),
            ])->values(),
            'summary' => [
                'opening' => $signed($statement->openingNet()),
                'closing' => $signed($statement->closingNet()),
                'they_owe_opening' => $statement->customerSide->opening->format(),
                'they_owe_closing' => $statement->customerSide->closing()->format(),
                'we_owe_opening' => $statement->supplierSide->opening->format(),
                'we_owe_closing' => $statement->supplierSide->closing()->format(),
                'advance_opening' => $this->signedFormat($statement->advanceOpening),
                'advance_closing' => $this->signedFormat($statement->advanceClosing),
                'consistent' => $statement->consistent(),
            ],
        ]);
    }

    private function signedFormat(Money $amount): string
    {
        return ($amount->minor < 0 ? '-' : '').$amount->absolute()->format();
    }

    /** @return array{0: Carbon, 1: Carbon} the fiscal year to date by default */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();
        $yearStart = $business === null ? $today->copy()->startOfYear() : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? ReportDates::parse($request, 'from') : $yearStart;
        $to = $request->filled('to') ? ReportDates::parse($request, 'to') : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
