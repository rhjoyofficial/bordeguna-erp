<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ MONEY IN, FROM THE CUSTOMER'S SIDE (21 Sep 2026): "Receive payment" and a
 * list of every receipt.
 *
 * Until now a receipt could only be taken from one invoice's own page, one
 * invoice at a time, and there was no list of receipts anywhere: a dealer who
 * paid 50,000 taka against four invoices meant four receipts on four screens,
 * and "what did we receive this week?" had no answer. The engine
 * (RecordCustomerReceipt) always accepted several invoices in one receipt; this
 * is the screen it never had.
 *
 * ⚠ The amount is spread over the customer's oldest invoices first, and the
 * person sees and can change that split before anything is posted (settled with
 * ChatGPT: a default, shown, never silent).
 */
class ReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'customer_id' => $request->string('customer_id')->toString(),
            'money_account_id' => $request->string('money_account_id')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $query = CustomerReceipt::query()
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['money_account_id'], fn ($q, $id) => $q->where('money_account_id', $id))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('received_on', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('received_on', '<=', $to));

        $total = Money::ofTaka((string) ((clone $query)->sum('amount') ?: '0'));

        $receipts = $query
            ->with(['customer:id,name', 'moneyAccount:id,name', 'allocations.invoice:id,invoice_number', 'createdBy:id,name'])
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CustomerReceipt $receipt) => [
                'id' => $receipt->id,
                // ⚠ Formatted on the server: a date sliced on the page is a UTC
                // string, and a receipt taken early on the 1st read the 31st.
                'received_on' => $receipt->received_on?->format('d M Y'),
                'customer' => $receipt->customer?->name,
                'customer_id' => $receipt->customer_id,
                'money_account' => $receipt->moneyAccount?->name,
                'amount' => Money::ofTaka((string) $receipt->amount)->format(),
                'reference' => $receipt->reference,
                'invoices' => $receipt->allocations->map(fn ($allocation) => [
                    'id' => $allocation->sales_invoice_id,
                    'number' => $allocation->invoice?->invoice_number,
                    'amount' => Money::ofTaka((string) $allocation->amount)->format(),
                ])->values(),
                'recorded_by' => $receipt->createdBy?->name,
            ]);

        return Inertia::render('sales/receipts/index', [
            'receipts' => $receipts,
            'total' => $total->format(),
            'filters' => $filters,
            'customers' => Customer::query()->listable()->orderBy('name')->get(['id', 'name']),
            'moneyAccounts' => MoneyAccount::query()->handled()->orderBy('name')->get(['id', 'name']),
            'canReceive' => (bool) $request->user()?->can('sales.receipt.record'),
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = $request->filled('customer_id') ? Customer::query()->findOrFail($request->integer('customer_id')) : null;

        return Inertia::render('sales/receipts/create', [
            'customers' => Customer::query()->listable()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name],
            'invoices' => $customer === null ? [] : $this->openInvoices($customer),
            // ⚠ Only accounts a person can take money into by hand AND that
            // have their ledger: a courier or gateway account fills itself, and
            // one with no ledger would be refused at the posting.
            // ⭐ F20: each with its balance now, for people who may read the
            // books (accounting or cost readers); null for everybody else, so a
            // counter hand taking a payment does not read the bank balance.
            'moneyAccounts' => tap(MoneyAccount::query()->handled()->where('is_active', true)->whereNotNull('account_id')
                ->orderBy('name')->get(['id', 'business_id', 'name', 'type', 'account_id']), function ($accounts) use ($request) {
                    $balances = $request->user()->can('accounting.view') || $request->user()->can('sales.reports.costs')
                        ? MoneyAccountBalances::for($accounts)
                        : [];
                    $accounts->each(fn (MoneyAccount $account) => $account->setAttribute('balance', $balances[$account->id] ?? null)
                        ->makeHidden(['business_id', 'account_id']));
                }),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordCustomerReceipt $record): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
        ], [
            'allocations.required' => 'Choose at least one invoice this money pays.',
            'allocations.*.invoice_id.distinct' => 'The same invoice is listed twice. Put the whole amount on one line.',
            'received_on.before_or_equal' => 'Money cannot be received on a future date.',
        ]);

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $invoices = SalesInvoice::query()
            ->whereIn('id', collect($data['allocations'])->pluck('invoice_id'))
            ->get()
            ->keyBy('id');

        $allocations = [];
        foreach ($data['allocations'] as $index => $entry) {
            $invoice = $invoices->get((int) $entry['invoice_id']);

            if ($invoice === null) {
                throw ValidationException::withMessages(["allocations.{$index}.amount" => 'That invoice is not in this business.']);
            }

            $allocations[] = ['invoice' => $invoice, 'amount' => (string) $entry['amount']];
        }

        $receipt = $record->handle(
            customer: $customer,
            moneyAccount: MoneyAccount::query()->findOrFail($data['money_account_id']),
            receivedOn: $data['received_on'],
            allocations: $allocations,
            createdBy: $request->user(),
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
        );

        return redirect()->route('sales.receipts.index')->with(
            'success',
            'Received '.Money::ofTaka((string) $receipt->amount)->format()." from {$customer->name}.",
        );
    }

    /**
     * The customer's unpaid invoices, oldest first: the order money is applied in.
     *
     * @return list<array<string, mixed>>
     */
    private function openInvoices(Customer $customer): array
    {
        // ⚠ SAL-009 (audit 14 Oct 2026): settled invoices are left out in SQL
        // and what each still owes comes back on the same row. This loaded
        // every invoice the customer ever had (the walk-in customer: every
        // cash sale) and ran six SUM queries for each.
        return SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->unsettled()
            ->withOutstandingPaisa()
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date?->format('d M Y'),
                'due' => $invoice->due_date?->format('d M Y'),
                'is_overdue' => $invoice->due_date !== null && $invoice->due_date->lt(Carbon::today()),
                'total' => $invoice->totalMoney()->format(),
                'outstanding' => Money::ofMinor((int) $invoice->outstanding_paisa)->toTaka(),
            ])
            ->values()
            ->all();
    }
}
