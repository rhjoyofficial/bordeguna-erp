<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CustomerLedger;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\PartyGroupBalances;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Tally's party ledgers as pages (party ledgers, 20 Oct 2026): the customer
 * ledger view (the mirror of the supplier ledger, also what the General
 * Ledger opens for a customer's 1140-<id> head) and the Sundry Creditors /
 * Sundry Debtors groups, each party a row that drills to its ledger, and
 * each ledger line to its document.
 */
class PartyLedgerController extends Controller
{
    public function customer(Request $request): Response
    {
        [$from, $to] = $this->window($request);

        $customers = Customer::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn (Customer $option) => [
            'id' => $option->id,
            'label' => $option->code ? "{$option->name} ({$option->code})" : $option->name,
        ]);

        $customer = $request->filled('customer') ? Customer::query()->find($request->integer('customer')) : null;

        return Inertia::render('accounting/customer-ledger/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'customers' => $customers,
            'ledger' => $customer === null ? null : CustomerLedger::for($customer, $from, $to)->toArray(),
        ]);
    }

    public function creditors(Request $request): Response
    {
        return $this->group($request, PartyGroupBalances::CREDITORS);
    }

    public function debtors(Request $request): Response
    {
        return $this->group($request, PartyGroupBalances::DEBTORS);
    }

    private function group(Request $request, string $group): Response
    {
        [$from, $to] = $this->window($request);

        return Inertia::render('accounting/party-groups/index', PartyGroupBalances::for($group, $from, $to, $request->boolean('all')));
    }

    /**
     * Defaults to the fiscal year to date, like every other accounting report.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());
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
