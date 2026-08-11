<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalesInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $invoices = SalesInvoice::query()
            ->with('customer:id,name')
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // ⚠ Outstanding is DERIVED per invoice from its allocations, never
        // stored. Computed here for display only.
        $invoices->getCollection()->transform(function (SalesInvoice $invoice) {
            $invoice->setAttribute('outstanding', $invoice->outstanding()->toTaka());
            $invoice->setAttribute('is_settled', $invoice->isSettled());

            return $invoice;
        });

        $rows = $invoices->getCollection();

        if ($filters['status'] === 'open') {
            $invoices->setCollection($rows->reject->is_settled->values());
        } elseif ($filters['status'] === 'settled') {
            $invoices->setCollection($rows->filter->is_settled->values());
        }

        return Inertia::render('sales/invoices/index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'can_create' => $request->user()->can('sales.sell'),
        ]);
    }

    /**
     * Billing what left on challans - the month-end half of the distributor
     * rhythm, and the screen that discharges finding F1 of
     * SALES-ORDER-ARCHITECTURE.md §11.1: RecordSalesInvoice existed with no
     * route and no screen, so a challan could never actually be billed.
     *
     * ⚠⚠ THE SERVER SUPPLIES EVERY DEFAULT PRICE (C-SO-4): the order price
     * where the delivery line traces to a sales order - that is what "agreed"
     * means - and the resolver at today's date where it does not. A screen
     * that computes its own price is a second pricing engine.
     */
    public function create(Request $request, ResolveSellingPrice $resolver): Response
    {
        $customerId = $request->integer('customer_id') ?: null;
        $customer = $customerId !== null ? Customer::query()->findOrFail($customerId) : null;

        $billable = [];

        if ($customer !== null) {
            $lines = DeliveryLine::query()
                ->whereHas('delivery', fn ($q) => $q->where('customer_id', $customer->id))
                ->whereColumn('quantity_invoiced', '<', 'quantity')
                ->with(['delivery:id,delivery_number,delivered_at', 'product:id,name,sku', 'orderLine.order:id,order_number'])
                ->get();

            $billable = $lines->map(function (DeliveryLine $line) use ($resolver, $customer) {
                if ($line->orderLine !== null) {
                    $price = (string) $line->orderLine->unit_price;
                    $priceSource = "Agreed on {$line->orderLine->order->order_number}";
                } else {
                    $resolved = $resolver->find($line->product, $customer, $line->quantityUninvoiced());
                    $price = $resolved?->unitPrice;
                    $priceSource = $resolved?->explain() ?? 'No price rule - enter one';
                }

                return [
                    'delivery_line_id' => $line->id,
                    'delivery_number' => $line->delivery->delivery_number,
                    'delivered_at' => $line->delivery->delivered_at->toDateString(),
                    'product_name' => $line->product->name,
                    'product_sku' => $line->product->sku,
                    'quantity_uninvoiced' => $line->quantityUninvoiced(),
                    'unit_price' => $price,
                    'price_source' => $priceSource,
                ];
            })->values()->all();
        }

        return Inertia::render('sales/invoices/create', [
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
            'customerId' => $customerId,
            'billable' => $billable,
        ]);
    }

    public function store(Request $request, RecordSalesInvoice $action, ResolveSellingPrice $resolver): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['nullable', 'integer', 'exists:delivery_lines,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        $deliveryLines = DeliveryLine::query()
            ->whereIn('id', collect($data['lines'])->pluck('delivery_line_id')->filter())
            ->with(['product', 'orderLine'])
            ->get()
            ->keyBy('id');

        $invoiceDate = $data['invoice_date'] ?? null;

        $lines = collect($data['lines'])
            ->filter(fn (array $line) => (float) $line['quantity'] > 0)
            ->map(function (array $line) use ($deliveryLines, $resolver, $customer, $invoiceDate) {
                $deliveryLine = isset($line['delivery_line_id']) ? $deliveryLines[$line['delivery_line_id']] ?? null : null;

                // ⚠⚠ C-SO-4. When the screen does not override, the price is
                // the ORDER price for an order-traced line, else the resolver
                // at the INVOICE date. Decided here, on the server, exactly
                // once - the create screen only previewed the same answer.
                $unitPrice = isset($line['unit_price']) ? (string) $line['unit_price'] : null;

                if ($unitPrice === null && $deliveryLine !== null) {
                    $unitPrice = $deliveryLine->orderLine !== null
                        ? (string) $deliveryLine->orderLine->unit_price
                        : $resolver->resolve($deliveryLine->product, $customer, (string) $line['quantity'], $invoiceDate)->unitPrice;
                }

                return [
                    'deliveryLine' => $deliveryLine,
                    'product' => $deliveryLine?->product,
                    'description' => $line['description'] ?? null,
                    'quantity' => (string) $line['quantity'],
                    'unitPrice' => $unitPrice ?? '0',
                    'discount' => isset($line['discount']) ? (string) $line['discount'] : '0',
                ];
            })
            ->values()
            ->all();

        $invoice = $action->handle(
            customer: $customer,
            lines: $lines,
            createdBy: $request->user(),
            invoiceDate: $invoiceDate,
            invoiceDiscount: (string) ($data['invoice_discount'] ?? '0'),
            dueDate: $data['due_date'] ?? null,
            note: $data['note'] ?? null,
        );

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_number} raised.");
    }

    public function show(SalesInvoice $invoice): Response
    {
        $invoice->load([
            'customer',
            'lines.product:id,name,sku',
            'lines.deliveryLine.delivery:id,delivery_number,delivered_at',
            'allocations.receipt.moneyAccount:id,name',
        ]);

        $journal = Journal::query()
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        return Inertia::render('sales/invoices/show', [
            'invoice' => $invoice,
            'outstanding' => $invoice->outstanding()->toTaka(),
            'received' => $invoice->receivedTotal()->toTaka(),
            'costReleased' => $invoice->costReleased()->toTaka(),
            'isSettled' => $invoice->isSettled(),
            // ⚠ The journal is shown on the invoice on purpose. "Show me what
            // this did to the books" is the question that makes an accountant
            // trust the system, and hiding it behind the ledger screen is how
            // people stop checking.
            'journal' => $journal,
            'moneyAccounts' => MoneyAccount::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Taking money against this invoice.
     */
    public function receipt(Request $request, SalesInvoice $invoice, RecordCustomerReceipt $action): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $action->handle(
            customer: $invoice->customer,
            moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
            receivedOn: $data['received_on'],
            allocations: [['invoice' => $invoice, 'amount' => (string) $data['amount']]],
            createdBy: $request->user(),
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
        );

        return back()->with('success', 'Receipt recorded.');
    }
}
