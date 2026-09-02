<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        try {
            $lines = collect($data['lines'])
                ->filter(fn (array $line) => (float) $line['quantity'] > 0)
                ->map(function (array $line, int $index) use ($deliveryLines, $resolver, $customer, $invoiceDate) {
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

                    // ⚠⚠ C-SLS-E2. A line billing no delivery never reaches the
                    // resolver above, so an omitted price used to fall through to
                    // zero and bill the customer nothing. A service line releases
                    // no cost, so there is no COGS to make the mistake visible and
                    // the books balance perfectly around the missing money.
                    // ⚠ Refused on ABSENCE, never on value: an explicit 0 is a
                    // free sample or a written off charge, which is a real thing a
                    // business does and is not mine to forbid.
                    if ($unitPrice === null) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.unit_price" => 'This line bills no delivery, so it needs a price. Enter 0 if it is deliberately free.',
                        ]);
                    }

                    return [
                        'deliveryLine' => $deliveryLine,
                        'product' => $deliveryLine?->product,
                        'description' => $line['description'] ?? null,
                        'quantity' => (string) $line['quantity'],
                        'unitPrice' => $unitPrice,
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
        } catch (NoSellingPriceException $e) {
            // ⚠ C-SLS-E1. The message names the product and the date it looked on.
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['invoice_date' => $e->getMessage()]);
        }

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
            // The delivery a return against this invoice would come back on -
            // the invoice page is where a counter return naturally starts.
            'returnDeliveryId' => $invoice->lines
                ->pluck('deliveryLine.delivery.id')
                ->filter()
                ->unique()
                ->first(),
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
     * The copy the customer is handed.
     *
     * ⚠⚠ THE BROWSER'S PRINT DIALOG IS THE DOWNLOAD BUTTON, the same decision the
     * show-cause notice took: save-as-PDF is built into every browser, and a PDF
     * library here would be a second renderer to keep in step with this one.
     *
     * ⚠ Read-only and separate from `show()` on purpose. `show()` is the
     * internal view and carries the journal, the cost released and the money
     * accounts; none of that belongs on a document that leaves the building.
     */
    public function print(SalesInvoice $invoice): Response
    {
        $invoice->load(['customer', 'lines.product:id,name,sku']);

        return Inertia::render('sales/invoices/print', [
            'invoice' => $invoice,
            'outstanding' => $invoice->outstanding()->toTaka(),
            'received' => $invoice->receivedTotal()->toTaka(),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    /**
     * The thermal slip (POS spec §19): the paper a counter customer walks
     * away with. 80mm of shop name, lines, payment and seller - and a barcode
     * of the invoice number, which is the SAME number the returns screen
     * accepts, so a scan closes the loop. Reprint is visiting this page
     * again; the slip computes nothing itself.
     */
    public function slip(SalesInvoice $invoice): Response
    {
        $invoice->load([
            'customer:id,name,phone',
            'lines.product:id,name,sku',
            'allocations.receipt.moneyAccount:id,name',
        ]);

        return Inertia::render('sales/invoices/slip', [
            'invoice' => $invoice,
            'outstanding' => $invoice->outstanding()->toTaka(),
            'payments' => $invoice->allocations->map(fn ($allocation) => [
                'account' => $allocation->receipt?->moneyAccount?->name,
                'amount' => $allocation->amount,
                'received_on' => $allocation->receipt?->received_on,
            ])->values(),
            'soldBy' => \App\Models\User::query()->where('id', $invoice->created_by)->value('name'),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'barcodeSvg' => \App\Modules\Inventory\Support\Code128::svg($invoice->invoice_number, 56, 11),
        ]);
    }

    /**
     * The money receipt, which is the paper that proves a payment was made.
     *
     * ⚠ A receipt may settle SEVERAL invoices at once, so it is printed from
     * the receipt and not from any one of them. Printing it per invoice would
     * hand the customer a document that names one bill and states an amount
     * covering three.
     */
    public function printReceipt(CustomerReceipt $receipt): Response
    {
        $receipt->load([
            'customer',
            'moneyAccount:id,name,type',
            'allocations.invoice:id,invoice_number,invoice_date,total',
        ]);

        return Inertia::render('sales/receipts/print', [
            'receipt' => $receipt,
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
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
