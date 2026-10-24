<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\ApplyCustomerAdvances;
use App\Modules\Sales\Actions\RecordCustomerAdvance;
use App\Modules\Sales\Actions\RefundCustomerAdvance;
use App\Modules\Sales\Corrections\CustomerAdvanceActionPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceApplicationPolicy;
use App\Modules\Sales\Corrections\CustomerAdvanceRefundPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\CustomerAdvanceBook;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ CUSTOMER ADVANCES (Joy's initial plan R90, B11, 24 Oct 2026): "a dealer's
 * advance is held in a liability account and deducted when we invoice him."
 *
 *   index      Sales > Money > Advances: every advance, what each still has
 *              open, where it was used and what went back; Reverse on each
 *   store      Receive advance (no invoice, no order): CADV
 *   refund     Pay an advance back: CARF
 *   apply      Use advances on an invoice (the invoice page): CAAP, oldest
 *              first by default, the person may change the split
 *   fromOrder  A cancelled order's money moved to the advance (gap S6)
 *
 * ⚠ Every refusal is a sentence on the field it is about; nothing here posts a
 * journal by hand (PostCustomerAdvanceJournals through PostJournal).
 */
class CustomerAdvanceController extends Controller
{
    public function index(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;
        $customer = $customerId === null ? null : Customer::query()->find($customerId);
        $user = $request->user();

        $advancePolicy = app(CustomerAdvanceActionPolicy::class);
        $usePolicy = app(CustomerAdvanceApplicationPolicy::class);
        $refundPolicy = app(CustomerAdvanceRefundPolicy::class);

        $advances = CustomerAdvance::query()
            ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['customer:id,name', 'moneyAccount:id,name', 'salesOrder:id,order_number'])
            ->orderByDesc('received_on')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (CustomerAdvance $advance) => [
                'id' => $advance->id,
                'number' => $advance->number,
                'kind' => $advance->kind,
                'received_on' => $advance->received_on->toDateString(),
                'customer' => $advance->customer?->name,
                'customer_id' => $advance->customer_id,
                'money_account' => $advance->moneyAccount?->name,
                'order' => $advance->salesOrder?->only(['id', 'order_number']),
                'reference' => $advance->reference,
                'amount' => $advance->money()->toTaka(),
                'open' => $advance->open()->toTaka(),
                'reversed' => $advance->isReversed(),
                'actions' => $advancePolicy->actionsFor($advance, $user)->toArray()['actions'],
            ]);

        $uses = CustomerAdvanceApplication::query()
            ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['customer:id,name', 'invoice:id,invoice_number', 'advance:id,number'])
            ->orderByDesc('applied_on')->orderByDesc('id')->limit(50)->get()
            ->map(fn (CustomerAdvanceApplication $use) => [
                'id' => $use->id,
                'number' => $use->number,
                'applied_on' => $use->applied_on->toDateString(),
                'customer' => $use->customer?->name,
                'invoice' => $use->invoice?->only(['id', 'invoice_number']),
                'from' => $use->advance?->number ?? 'On account',
                'amount' => $use->money()->toTaka(),
                'reversed' => $use->isReversed(),
                'actions' => $usePolicy->actionsFor($use, $user)->toArray()['actions'],
            ])->values();

        $refunds = CustomerAdvanceRefund::query()
            ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
            ->with(['customer:id,name', 'moneyAccount:id,name', 'advance:id,number'])
            ->orderByDesc('refunded_on')->orderByDesc('id')->limit(50)->get()
            ->map(fn (CustomerAdvanceRefund $refund) => [
                'id' => $refund->id,
                'number' => $refund->number,
                'refunded_on' => $refund->refunded_on->toDateString(),
                'customer' => $refund->customer?->name,
                'money_account' => $refund->moneyAccount?->name,
                'from' => $refund->advance?->number ?? 'On account',
                'amount' => $refund->money()->toTaka(),
                'reversed' => $refund->isReversed(),
                'actions' => $refundPolicy->actionsFor($refund, $user)->toArray()['actions'],
            ])->values();

        return Inertia::render('sales/advances/index', [
            'filters' => ['customer_id' => $customer === null ? '' : (string) $customer->id],
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'with_us' => CustomerAdvanceBook::totalOpen($customer)->toTaka(),
                'rows' => CustomerAdvanceBook::rows($customer),
            ],
            'advances' => $advances,
            'uses' => $uses,
            'refunds' => $refunds,
            'customers' => Customer::query()->listable()->orderBy('name')->get(['id', 'name']),
            'moneyAccounts' => $user->can('sales.receipt.record') || $user->can('sales.returns.refund')
                ? MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'canReceive' => $user->can('sales.receipt.record'),
            'canRefund' => $user->can('sales.returns.refund'),
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordCustomerAdvance $record): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'received_on.before_or_equal' => 'Money cannot be received on a future date.',
        ]);

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $account = MoneyAccount::query()->findOrFail($data['money_account_id']);

        try {
            $advance = $record->receive($customer, $account, $data['received_on'], (string) $data['amount'], $request->user(), $data['reference'] ?? null, $data['note'] ?? null);
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($account, $e)]);
        }

        return back()->with('success', "Advance {$advance->number} of ".$advance->money()->format()." kept for {$customer->name}.");
    }

    public function refund(Request $request, RefundCustomerAdvance $refund): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'refunded_on' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'key' => ['required', 'string', 'max:40'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'refunded_on.before_or_equal' => 'Money cannot go back on a future date.',
        ]);

        $customer = Customer::query()->findOrFail($data['customer_id']);
        $rows = ApplyCustomerAdvances::rowsFrom([['key' => $data['key'], 'amount' => (string) $data['amount']]]);
        $account = MoneyAccount::query()->findOrFail($data['money_account_id']);

        try {
            $made = $refund->handle($customer, $rows[0]['advance'] ?? null, $account, $data['refunded_on'], (string) $data['amount'], $request->user(), $data['reference'] ?? null, $data['note'] ?? null);
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($account, $e)]);
        }

        return back()->with('success', "Refund {$made->number} of ".$made->money()->format()." to {$customer->name} recorded.");
    }

    public function apply(Request $request, SalesInvoice $invoice, ApplyCustomerAdvances $apply): RedirectResponse
    {
        $data = $request->validate([
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'advances' => ['required', 'array', 'min:1'],
            'advances.*.key' => ['required', 'string', 'max:40', 'distinct'],
            'advances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'advances.*.key.distinct' => 'The same advance is listed twice.',
        ]);

        $made = $apply->handle($invoice, ApplyCustomerAdvances::rowsFrom($data['advances']), $data['applied_on'], $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Advance used on invoice '.$invoice->invoice_number.': '.Money::sum($made->map(fn ($use) => $use->money()))->format().'.');
    }

    public function fromOrder(Request $request, SalesOrder $order, RecordCustomerAdvance $record): RedirectResponse
    {
        $data = $request->validate([
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $advance = $record->fromOrder($order, $data['moved_on'], $request->user(), isset($data['amount']) ? (string) $data['amount'] : null, $data['note'] ?? null);

        return back()->with('success', $advance->money()->format()." moved to {$order->customer?->name}'s advance ({$advance->number}).");
    }
}
