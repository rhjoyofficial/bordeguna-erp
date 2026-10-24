<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\ApplyCustomerAdvances;
use App\Modules\Sales\Actions\RecordCustomerAdvance;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\CustomerReceiptDuplicateRule;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\CustomerAdvanceBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // ⭐ Corrections wave 1: a reversed receipt stays in the list, marked,
        // and is not in the total.
        $total = Money::ofTaka((string) ((clone $query)->whereNull('reversed_at')->sum('amount') ?: '0'));
        $policy = app(CustomerReceiptActionPolicy::class);
        $user = $request->user();

        $receipts = $query
            ->with(['customer:id,name', 'moneyAccount:id,name', 'allocations.invoice:id,invoice_number', 'createdBy:id,name'])
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CustomerReceipt $receipt) => [
                'id' => $receipt->id,
                'label' => CustomerReceiptActionPolicy::label($receipt),
                'reversed' => $receipt->isReversed(),
                'reversal' => $receipt->reversalSummary(),
                'correction_of' => CorrectionChain::correctionOfLabel($receipt),
                'released' => $receipt->reversed_allocations ?? [],
                'actions' => $policy->actionsFor($receipt, $user)->toArray()['actions'],
                'can_reallocate' => $reallocatable = (! $receipt->isReversed() && $receipt->voucher_id === null && $receipt->isCash() && $user->can('sales.receipt.record') && $receipt->allocations->isNotEmpty()),
                // The customer's other invoices that still owe, for Re-allocate.
                'open_invoices' => $reallocatable
                    ? SalesInvoice::query()->live()->where('customer_id', $receipt->customer_id)->unsettled()->withOutstandingPaisa()
                        ->orderBy('invoice_date')->get(['id', 'invoice_number'])
                        ->map(fn (SalesInvoice $i) => ['id' => $i->id, 'number' => $i->invoice_number, 'outstanding' => Money::ofMinor((int) $i->outstanding_paisa)->format()])->values()
                    : [],
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
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'correctionReasons' => Reason::query()->where('context', 'correction')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = $request->filled('customer_id') ? Customer::query()->findOrFail($request->integer('customer_id')) : null;

        return Inertia::render('sales/receipts/create', [
            'customers' => Customer::query()->listable()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name],
            'invoices' => $customer === null ? [] : $this->openInvoices($customer),
            // ⭐ B11 (24 Oct 2026): their advance with us, oldest first, to use here.
            'advances' => $customer === null ? [] : CustomerAdvanceBook::rows($customer),
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

    public function store(Request $request, RecordCustomerReceipt $record, RecordCustomerAdvance $recordAdvance, ApplyCustomerAdvances $applyAdvances): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
            // ⭐ B11 (24 Oct 2026): what came in beyond the invoices, kept as the
            // customer's advance (the person ticks it; never silent), and the
            // customer's advances used on these invoices, oldest first.
            'keep_as_advance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'advance_uses' => ['nullable', 'array'],
            'advance_uses.*.invoice_id' => ['required', 'integer'],
            'advance_uses.*.key' => ['required', 'string', 'max:40'],
            'advance_uses.*.amount' => ['required', 'numeric', 'min:0'],
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
        ], [
            'allocations.*.invoice_id.distinct' => 'The same invoice is listed twice. Put the whole amount on one line.',
            'received_on.before_or_equal' => 'Money cannot be received on a future date.',
        ]);

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $invoiceIds = collect($data['allocations'] ?? [])->pluck('invoice_id')->concat(collect($data['advance_uses'] ?? [])->pluck('invoice_id'));
        $invoices = SalesInvoice::query()->whereIn('id', $invoiceIds)->get()->keyBy('id');

        $allocations = [];
        foreach ($data['allocations'] ?? [] as $index => $entry) {
            $invoice = $invoices->get((int) $entry['invoice_id']);

            if ($invoice === null) {
                throw ValidationException::withMessages(["allocations.{$index}.amount" => 'That invoice is not in this business.']);
            }

            if (Money::ofTaka((string) $entry['amount'])->minor > 0) {
                $allocations[] = ['invoice' => $invoice, 'amount' => (string) $entry['amount']];
            }
        }

        $uses = [];
        foreach ($data['advance_uses'] ?? [] as $index => $entry) {
            $invoice = $invoices->get((int) $entry['invoice_id']);

            if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                throw ValidationException::withMessages(["advance_uses.{$index}.amount" => 'That invoice is not one of this customer\'s.']);
            }

            $uses[$invoice->id][] = ['key' => $entry['key'], 'amount' => (string) $entry['amount']];
        }

        $keep = Money::ofTaka((string) ($data['keep_as_advance'] ?? '0'));
        $cash = Money::ofMinor(array_sum(array_map(fn (array $a) => Money::ofTaka($a['amount'])->minor, $allocations)) + $keep->minor);

        if ($cash->isZero() && $uses === []) {
            throw ValidationException::withMessages(['allocations' => 'Choose the invoices this money pays, or keep it as the customer\'s advance.']);
        }

        if (! $cash->isZero() && empty($data['money_account_id'])) {
            throw ValidationException::withMessages(['money_account_id' => 'Choose the account the money came into.']);
        }

        // ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 5): "Possible
        // duplicate" (same customer, same amount, within 3 days) before the
        // receipt is saved; "Save anyway" is kept beside it.
        $guard = app(DuplicateGuard::class);
        $payload = [
            'customer_id' => $customer->id,
            'received_on' => $data['received_on'],
            'amount' => $cash->toTaka(),
        ];

        try {
            [$receipt, $advance] = DB::transaction(function () use ($guard, $payload, $request, $businessId, $data, $record, $recordAdvance, $applyAdvances, $customer, $allocations, $uses, $keep, $cash, $invoices) {
                $ack = $cash->isZero() ? null : $guard->check(app(CustomerReceiptDuplicateRule::class), $payload, $request->user(), (int) $businessId, $data['duplicate_ack'] ?? null);

                // The advance first: it settles the oldest invoices, and the
                // money then pays what is left on them.
                foreach ($uses as $invoiceId => $rows) {
                    $applyAdvances->handle($invoices->get($invoiceId), ApplyCustomerAdvances::rowsFrom($rows), $data['received_on'], $request->user());
                }

                $account = $cash->isZero() ? null : MoneyAccount::query()->findOrFail($data['money_account_id']);
                $receipt = $allocations === [] ? null : $record->handle(
                    customer: $customer,
                    moneyAccount: $account,
                    receivedOn: $data['received_on'],
                    allocations: $allocations,
                    createdBy: $request->user(),
                    reference: $data['reference'] ?? null,
                    note: $data['note'] ?? null,
                );

                $advance = $keep->isZero() ? null : $recordAdvance->receive(
                    $customer, $account, $data['received_on'], $keep->toTaka(), $request->user(),
                    $data['reference'] ?? null, $data['note'] ?? null, $receipt,
                );

                if ($ack !== null) {
                    $guard->record($ack, $receipt ?? $advance);
                }

                return [$receipt, $advance];
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different payment?']);
        }

        $said = [];

        if ($receipt !== null) {
            $said[] = 'Received '.Money::ofTaka((string) $receipt->amount)->format()." from {$customer->name}.";
        }

        if ($advance !== null) {
            $said[] = $advance->money()->format()." kept as {$customer->name}'s advance ({$advance->number}).";
        }

        if ($uses !== []) {
            $said[] = "{$customer->name}'s advance used on their invoices.";
        }

        return redirect()->route('sales.receipts.index')->with('success', implode(' ', $said));
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
