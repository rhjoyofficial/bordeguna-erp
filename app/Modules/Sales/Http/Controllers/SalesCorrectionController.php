<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\Corrections\IssuePriceCredit;
use App\Modules\Sales\Actions\Corrections\RaiseExtraCharge;
use App\Modules\Sales\Actions\Corrections\ReallocateCustomerReceipt;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ The sales corrections that are not the engine's Reverse and Correct
 * (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2), and the two Correct pages
 * the engine's dialog submits from:
 *
 *   GET  invoices/{invoice}/correct        the invoice form, pre-filled, date first
 *   POST invoices/{invoice}/price-credit   a price credit note (goods kept)
 *   POST invoices/{invoice}/extra-charge   an extra charge (a debit note)
 *   GET  receipts/{receipt}/correct        the receipt form, pre-filled, date first
 *   POST receipts/{receipt}/reallocate     move money to another invoice of the customer
 *
 * Every action asks for a reason (5 characters, checked here, not in the
 * dialog). Credit, extra charge: sales.correct. Re-allocate: whoever records
 * receipts (sales.receipt.record).
 */
class SalesCorrectionController extends Controller
{
    use ValidatesActionReason;

    public function correctInvoice(Request $request, SalesInvoice $invoice, SalesInvoiceActionPolicy $policy): Response
    {
        abort_unless($policy->canView($invoice, $request->user()), 404);
        $availability = $policy->availability($invoice, $request->user(), DocumentAction::Correct);
        abort_unless($request->user()->can($availability->permission), 403);

        $invoice->load(['customer:id,name', 'lines.product:id,name,sku', 'lines.deliveryLine.delivery:id,delivery_number,delivered_at']);

        return Inertia::render('sales/invoices/correct', [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer' => $invoice->customer?->name,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'invoice_discount' => Money::ofTaka((string) $invoice->invoice_discount)->toTaka(),
                'note' => $invoice->note,
                'total' => Money::ofTaka((string) $invoice->total)->toTaka(),
                'lines' => $invoice->lines->whereNull('billed_delivery_for_order_id')->map(fn ($line) => [
                    'id' => $line->id,
                    'product' => $line->product?->name ?? $line->description,
                    'challan' => $line->deliveryLine?->delivery?->delivery_number,
                    'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.'),
                    'unit_price' => rtrim(rtrim(number_format((float) $line->unit_price, 4, '.', ''), '0'), '.'),
                    'discount' => Money::ofTaka((string) $line->line_discount)->toTaka(),
                    'description' => $line->description,
                ])->values(),
                'delivery_charges' => $invoice->lines->whereNotNull('billed_delivery_for_order_id')->map(fn ($line) => [
                    'description' => $line->description,
                    'amount' => Money::ofTaka((string) $line->line_net)->toTaka(),
                ])->values(),
            ],
            'actions' => [$availability->toArray()],
            'reasons' => $this->reasons('correction'),
            'canOverridePrice' => $request->user()->can('sales.price.override'),
        ]);
    }

    public function priceCredit(Request $request, SalesInvoice $invoice, IssuePriceCredit $action): RedirectResponse
    {
        $this->authorize(SalesInvoiceActionPolicy::PERMISSION);

        $data = $request->validate([
            'credit_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            ...$this->actionReasonRules('correction'),
        ], ['credit_date.before_or_equal' => 'A credit note cannot be dated in the future.'] + $this->actionReasonMessages());

        try {
            $note = $action->handle($invoice, $data['credit_date'], (string) $data['amount'], $this->actionReason($request), $request->user());
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['credit_date' => $e->getMessage()]);
        }

        return back()->with('success', "Credit note {$note->credit_note_number} issued on {$invoice->invoice_number}.");
    }

    public function extraCharge(Request $request, SalesInvoice $invoice, RaiseExtraCharge $action): RedirectResponse
    {
        $this->authorize(SalesInvoiceActionPolicy::PERMISSION);

        $data = $request->validate([
            'charge_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'description' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date', 'after_or_equal:charge_date'],
            ...$this->actionReasonRules('correction'),
        ], ['charge_date.before_or_equal' => 'An extra charge cannot be dated in the future.'] + $this->actionReasonMessages());

        try {
            $extra = $action->handle($invoice, $data['charge_date'], (string) $data['amount'], $data['description'], $this->actionReason($request), $request->user(), $data['due_date'] ?? null);
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['charge_date' => $e->getMessage()]);
        }

        return redirect()->route('sales.invoices.show', $extra)->with('success', "Extra charge {$extra->invoice_number} raised on {$invoice->invoice_number}.");
    }

    public function correctReceipt(Request $request, CustomerReceipt $receipt, CustomerReceiptActionPolicy $policy): Response
    {
        abort_unless($policy->canView($receipt, $request->user()), 404);
        $availability = $policy->availability($receipt, $request->user(), DocumentAction::Correct);
        abort_unless($request->user()->can($availability->permission), 403);

        $receipt->load(['customer:id,name', 'allocations.invoice:id,invoice_number,invoice_date,total']);

        return Inertia::render('sales/receipts/correct', [
            'receipt' => [
                'id' => $receipt->id,
                'label' => CustomerReceiptActionPolicy::label($receipt),
                'customer_id' => $receipt->customer_id,
                'money_account_id' => $receipt->money_account_id,
                'received_on' => $receipt->received_on->toDateString(),
                'amount' => Money::ofTaka((string) $receipt->amount)->toTaka(),
                'reference' => $receipt->reference,
                'note' => $receipt->note,
                'allocations' => $receipt->allocations->map(fn ($a) => [
                    'sales_invoice_id' => $a->sales_invoice_id,
                    'amount' => Money::ofTaka((string) $a->amount)->toTaka(),
                ])->values(),
            ],
            'customers' => Customer::query()->listable()->active()->orderBy('name')->get(['id', 'name']),
            // Invoices that can take money: the customer's live ones that still
            // owe, plus those this receipt pays (they owe again once it is undone).
            'invoices' => SalesInvoice::query()->live()->withOutstandingPaisa()
                ->where(fn ($q) => $q->unsettled()->orWhereIn('id', $receipt->allocations->pluck('sales_invoice_id')))
                ->orderBy('invoice_date')->get(['id', 'customer_id', 'invoice_number', 'invoice_date', 'total'])
                ->map(fn (SalesInvoice $invoice) => [
                    'id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date->toDateString(),
                    'outstanding' => Money::ofMinor((int) $invoice->outstanding_paisa + (int) round((float) $receipt->allocations->where('sales_invoice_id', $invoice->id)->sum('amount') * 100))->toTaka(),
                ])->values(),
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'actions' => [$availability->toArray()],
            'reasons' => $this->reasons('correction'),
        ]);
    }

    public function reallocate(Request $request, CustomerReceipt $receipt, ReallocateCustomerReceipt $action): RedirectResponse
    {
        $this->authorize('sales.receipt.record');
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'from_invoice_id' => ['required', 'integer', Rule::exists('sales_invoices', 'id')->where('business_id', $businessId)],
            'to_invoice_id' => ['required', 'integer', Rule::exists('sales_invoices', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            ...$this->actionReasonRules('correction'),
        ], $this->actionReasonMessages());

        $action->handle(
            $receipt,
            SalesInvoice::query()->findOrFail($data['from_invoice_id']),
            SalesInvoice::query()->findOrFail($data['to_invoice_id']),
            (string) $data['amount'],
            $this->actionReason($request),
            $request->user(),
        );

        return back()->with('success', 'Money moved to the other invoice.');
    }

    /** @return list<array{id: int, name: string}> */
    private function reasons(string $context): array
    {
        return Reason::query()->where('context', $context)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Reason $r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->all();
    }
}
