<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Foundation\Support\DocumentMoney;
use App\Modules\Foundation\Support\PrintableDocument;
use App\Modules\Foundation\Support\ThermalProfile;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Corrections\ExtraChargePolicy;
use App\Modules\Sales\Corrections\PriceCreditPolicy;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\AmountInWords;
use App\Modules\Sales\Support\CostVisibility;
use App\Modules\Sales\Support\DeliveredBatches;
use App\Modules\Sales\Support\DiscountCap;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\OwnerPriceVisibility;
use App\Support\Documents\RelatedDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        // ⭐ Joy, 11 Oct 2026: by the brand of the order it came from.
        $businessId = (int) app(BusinessContext::class)->id();
        $brand = OrderBrand::filter($request->query('brand'), $businessId);
        $filters['brand'] = OrderBrand::filterValue($brand);
        $brandNames = OrderBrand::names($businessId);

        $invoices = SalesInvoice::query()
            ->tap(fn ($query) => OrderBrand::onInvoices($query, $brand))
            // ⭐ Phase 2 (17 Oct 2026): an employee's staff purchases are
            // theirs to know about, plus Owner, HR authority and Finance.
            ->when(! Customer::staffVisibleTo($request->user()), fn ($query) => $query->where('is_staff_sale', false))
            ->with('customer:id,name')
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            // ⚠⚠ Filtered in SQL, BEFORE paginate. It used to filter the twenty
            // rows already paginated, so "Unpaid" hid every older debt and the
            // pager still counted all invoices (audit #12, 21 Sep 2026).
            ->when($filters['status'] === 'open', fn ($query) => $query->unsettled())
            ->when($filters['status'] === 'settled', fn ($query) => $query->settled())
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $brandOf = OrderBrand::forInvoices(collect($invoices->items())->pluck('id'));

        $invoices = $invoices
            // ⚠ Outstanding is DERIVED per invoice from its allocations, never
            // stored. Computed here for display only. ⚠ The date as a Dhaka
            // date: handed over inside the model it serialises as a UTC
            // timestamp, and the list sliced that, showing every invoice a day
            // early (the lesson show() and print() had already learned).
            ->through(fn (SalesInvoice $invoice) => array_merge($invoice->toArray(), [
                // Through its challan lines to the order (Joy, 11 Oct 2026).
                'brand' => isset($brandOf[$invoice->id]) ? ($brandNames[$brandOf[$invoice->id]] ?? null) : null,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'outstanding' => $invoice->outstanding()->toTaka(),
                'is_settled' => $invoice->isSettled(),
                // Some money in, not all: "Unpaid" said the opposite (21 Sep 2026).
                'is_part_paid' => ! $invoice->isSettled() && $invoice->receivedTotal()->minor > 0,
                // ⭐ Corrections wave 1: taken back whole by a credit note.
                'credited' => $invoice->isReversed(),
            ]));

        return Inertia::render('sales/invoices/index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'brands' => OrderBrand::options($businessId),
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
                // ⭐ Corrections wave 1: a reversed challan is not billable.
                ->whereHas('delivery', fn ($q) => $q->where('customer_id', $customer->id)->whereNull('reversed_at')
                    // ⚠ A parcel still with a courier is invoiced by the courier's
                    // answer, so it is not offered here to be billed by hand.
                    ->whereDoesntHave('consignment', fn ($c) => $c->where('status', ConsignmentStatus::Booked)))
                // ⚠ SAL-008 (audit 14 Oct 2026): what is left to bill, the same
                // sum as DeliveryLine::quantityUninvoiced(). Goods returned
                // before billing count too, so a line returned whole is no
                // longer offered with "0 uninvoiced".
                ->whereRaw('quantity - quantity_invoiced - quantity_returned_uninvoiced > 0')
                ->with(['delivery:id,delivery_number,delivered_at', 'product:id,name,sku', 'orderLine.order:id,order_number'])
                ->get();

            $billable = $lines->map(function (DeliveryLine $line) use ($resolver, $customer) {
                $agreedDiscount = null;

                if ($line->orderLine !== null) {
                    $price = (string) $line->orderLine->unit_price;
                    $priceSource = "Agreed on {$line->orderLine->order->order_number}";

                    // ⚠ Previewed for the whole uninvoiced quantity. The screen
                    // sends the discount back EMPTY unless somebody types one,
                    // and the server then decides it for the quantity actually
                    // billed - so a partial bill cannot carry a full share.
                    $discount = $line->orderLine->discountToBill($line->quantityUninvoiced());
                    $agreedDiscount = $discount->isZero() ? null : $discount->toTaka();

                    if ($line->orderLine->bundle_label) {
                        $priceSource .= " ({$line->orderLine->bundle_label})";
                    }
                } elseif ($customer->isOwner()) {
                    // ⭐ D-B (18 Oct 2026): the batches this challan took, at cost.
                    $price = $line->unitCostTaken();
                    $priceSource = $price === null ? 'No price rule - enter one' : 'Owner price: batch cost';
                } else {
                    $resolved = $resolver->find($line->product, $customer, $line->quantityUninvoiced());
                    $price = $resolved?->unitPrice;
                    $priceSource = $resolved?->explain() ?? 'No price rule - enter one';
                }

                return [
                    'delivery_line_id' => $line->id,
                    'delivery_id' => $line->delivery_id,
                    'delivery_number' => $line->delivery->delivery_number,
                    'delivered_at' => $line->delivery->delivered_at->toDateString(),
                    'product_name' => $line->product->name,
                    'product_sku' => $line->product->sku,
                    'quantity_uninvoiced' => $line->quantityUninvoiced(),
                    'unit_price' => $price,
                    'agreed_discount' => $agreedDiscount,
                    'price_source' => $priceSource,
                ];
            })->values()->all();

            // ⭐ Slice 2.5b: the discount each order agreed on the WHOLE order (a
            // coupon), previewed for billing everything shown. ⚠ Preview only -
            // `RecordSalesInvoice` decides the figure for what is actually
            // billed, under a lock, and bills it whatever the screen sends.
            foreach ($lines->filter(fn (DeliveryLine $line) => $line->orderLine !== null)->groupBy(fn ($line) => $line->orderLine->sales_order_id) as $orderId => $orderLines) {
                $order = SalesOrder::query()->with('lines')->find($orderId);

                if ($order === null) {
                    continue;
                }

                // ⭐ The delivery charge rides on the first invoice billing any
                // of the order's goods. Preview only, like the discount below.
                if ($order->deliveryCharge()->minor > 0 && ! $order->deliveryChargeBilled()) {
                    $agreedDeliveryCharges[] = [
                        'order_number' => $order->order_number,
                        'amount' => $order->deliveryCharge()->toTaka(),
                    ];
                }

                if ($order->orderDiscount()->isZero()) {
                    continue;
                }

                $netMinor = $orderLines->sum(fn (DeliveryLine $line) => Money::ofProduct($line->quantityUninvoiced(), (string) $line->orderLine->unit_price)->minor
                    - $line->orderLine->discountToBill($line->quantityUninvoiced())->minor);

                $finishes = $order->lines->every(fn ($orderLine) => (float) $orderLine->quantity_delivered + 0.00005 >= (float) $orderLine->quantity_ordered);

                $agreedOrderDiscounts[] = [
                    'order_number' => $order->order_number,
                    'coupon_code' => $order->coupon_code,
                    'amount' => $order->orderDiscountToBill(Money::ofMinor($netMinor), $finishes)->toTaka(),
                ];
            }
        }

        return Inertia::render('sales/invoices/create', [
            // ⭐ "Invoice this challan" from the order page (21 Sep 2026): only that
            // challan's lines start included; the customer's others start at 0.
            'onlyDeliveryId' => $request->integer('delivery_id') ?: null,
            'customers' => Customer::query()->listable()->active()->orderBy('name')->get(['id', 'name']),
            'customerId' => $customerId,
            'billable' => $billable,
            'agreed_order_discounts' => $agreedOrderDiscounts ?? [],
            'agreed_delivery_charges' => $agreedDeliveryCharges ?? [],
            'vat' => $this->vatFor($customerId),
        ]);
    }

    /**
     * What the invoice screen needs to offer the VAT choice (VAT plan D2).
     *
     * `applies` is false for every business today, and the screen then shows
     * nothing at all. When VAT is on, the starting answer is the selected
     * customer's price group, then the business; the clerk may change it for
     * this one invoice, and the saved invoice freezes whichever was used.
     *
     * @return array{applies: bool, default_inclusive: bool, bin: ?string}
     */
    private function vatFor(?int $customerId): array
    {
        $business = Business::query()->find(app(BusinessContext::class)->id());
        $group = $customerId === null ? null : Customer::query()->with('priceGroup')->find($customerId)?->priceGroup;

        return [
            'applies' => $business?->vatAppliesOn(Carbon::today()) ?? false,
            'default_inclusive' => (bool) ($group?->prices_include_tax ?? $business?->prices_include_tax ?? false),
            'bin' => $business?->vat_bin,
        ];
    }

    public function store(Request $request, RecordSalesInvoice $action, ResolveSellingPrice $resolver): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            // ⚠ Bug hunt 16 Oct 2026: the ledger refuses a future date, and that
            // refusal reached the page on a key it never showed, so Create
            // looked like it did nothing. Refused here, under the date.
            'invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            // A due date before the invoice date made the bill overdue the day
            // it was raised.
            'due_date' => ['nullable', 'date', $request->filled('invoice_date') ? 'after_or_equal:invoice_date' : 'after_or_equal:today'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            // Null = take the customer's price group, then the business.
            'prices_include_tax' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['nullable', 'integer', 'exists:delivery_lines,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'invoice_date.before_or_equal' => 'An invoice cannot be dated in the future.',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        $deliveryLines = DeliveryLine::query()
            ->whereIn('id', collect($data['lines'])->pluck('delivery_line_id')->filter())
            ->with(['product', 'orderLine'])
            ->get()
            ->keyBy('id');

        $invoiceDate = $data['invoice_date'] ?? null;
        $user = $request->user();
        $mayOverride = $user->can('sales.price.override');
        $refusals = [];
        $capLines = [];

        try {
            $mapped = collect($data['lines'])
                ->filter(fn (array $line) => (float) $line['quantity'] > 0)
                ->map(function (array $line, int $index) use ($deliveryLines, $resolver, $customer, $invoiceDate, $mayOverride, &$refusals, &$capLines) {
                    $deliveryLine = isset($line['delivery_line_id']) ? $deliveryLines[$line['delivery_line_id']] ?? null : null;

                    // ⚠⚠ C-SO-4. When the screen does not override, the price is
                    // the ORDER price for an order-traced line, else the resolver
                    // at the INVOICE date. Decided here, on the server, exactly
                    // once - the create screen only previewed the same answer.
                    $unitPrice = isset($line['unit_price']) ? (string) $line['unit_price'] : null;

                    if ($unitPrice === null && $deliveryLine !== null) {
                        $unitPrice = $deliveryLine->orderLine !== null
                            ? (string) $deliveryLine->orderLine->unit_price
                            : $this->unorderedPrice($resolver, $deliveryLine, $customer, (string) $line['quantity'], $invoiceDate);
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

                    // ⭐ 13 Oct 2026: the counter's price rules on the invoice too.
                    // Before this, anyone allowed to sell could type any price or
                    // discount here while the counter capped them. The yardstick is
                    // what the line would have billed untouched: the ORDER's price
                    // and agreed discount (approved on the order already), else the
                    // price list. A line with no product (a service charge) has no
                    // list to compare with and stays free-typed.
                    $quantity = (string) $line['quantity'];
                    $listPrice = null;
                    $agreedDiscount = '0';

                    if ($deliveryLine?->orderLine !== null) {
                        $listPrice = (string) $deliveryLine->orderLine->unit_price;
                        $agreedDiscount = $deliveryLine->orderLine->discountToBill($quantity)->toTaka();
                    } elseif ($deliveryLine !== null) {
                        $listPrice = $this->unorderedPrice($resolver, $deliveryLine, $customer, $quantity, $invoiceDate);
                    }

                    $name = $deliveryLine?->product?->name ?? 'this line';
                    $typedDiscount = isset($line['discount']) ? (string) $line['discount'] : null;
                    $extraDiscount = $typedDiscount === null ? '0' : bcsub($typedDiscount, $agreedDiscount, 2);
                    $extraDiscount = bccomp($extraDiscount, '0', 2) > 0 ? $extraDiscount : '0';

                    if ($listPrice !== null && bccomp($unitPrice, $listPrice, 4) !== 0 && ! $mayOverride) {
                        $refusals["lines.{$index}.unit_price"] = "Changing the price of {$name} needs the price-override permission, which your role does not have. It should bill at ".Money::ofTaka($listPrice)->format().'.';
                    }

                    if ($listPrice !== null && bccomp($extraDiscount, '0', 2) > 0 && ! $mayOverride) {
                        $refusals["lines.{$index}.discount"] = "A discount on {$name} needs the price-override permission, which your role does not have.";
                    }

                    if ($listPrice !== null) {
                        $capLines[] = ['quantity' => $quantity, 'unitPrice' => $unitPrice, 'discount' => $extraDiscount, 'listPrice' => $listPrice];
                    }

                    return [
                        'deliveryLine' => $deliveryLine,
                        'product' => $deliveryLine?->product,
                        'description' => $line['description'] ?? null,
                        'quantity' => (string) $line['quantity'],
                        'unitPrice' => $unitPrice,
                        // ⭐⭐ The agreed discount, the discount's C-SO-4. When the
                        // screen types nothing, a line that traces to a sales
                        // order bills that order's agreed discount for THIS
                        // quantity, with the last portion taking the remainder.
                        // A typed figure, including 0, is an override - the same
                        // seam the price has.
                        'discount' => isset($line['discount'])
                            ? (string) $line['discount']
                            : ($deliveryLine?->orderLine?->discountToBill((string) $line['quantity'])->toTaka() ?? '0'),
                    ];
                });

            // ⚠ SAL-007 (audit 14 Oct 2026): the form sends every billable row,
            // zero-quantity ones included, and only the filled ones reach the
            // action, re-numbered from 0. This remembers which form row each
            // action line came from, so a refusal lands under the row typed.
            $formRow = $mapped->keys()->all();
            $lines = $mapped->values()->all();

            $invoiceDiscount = (string) ($data['invoice_discount'] ?? '0');

            // The whole-bill discount is the same concession as a line one.
            if (Money::ofTaka($invoiceDiscount)->minor > 0 && ! $mayOverride) {
                $refusals['invoice_discount'] = 'Giving a discount needs the price-override permission, which your role does not have. Bill at the list price, or ask a Manager.';
            }

            // Joy's per-bill caps (26 Sep 2026), as at the counter.
            if ($refusals === []) {
                $overCap = DiscountCap::refusal($user, app(BusinessContext::class)->id(), $capLines, $invoiceDiscount);

                if ($overCap !== null) {
                    $refusals['invoice_discount'] = $overCap;
                }
            }

            if ($refusals !== []) {
                throw ValidationException::withMessages($refusals);
            }

            try {
                $invoice = $action->handle(
                    customer: $customer,
                    lines: $lines,
                    createdBy: $user,
                    invoiceDate: $invoiceDate,
                    invoiceDiscount: $invoiceDiscount,
                    dueDate: $data['due_date'] ?? null,
                    note: $data['note'] ?? null,
                    pricesIncludeTax: isset($data['prices_include_tax']) ? (bool) $data['prices_include_tax'] : null,
                );
            } catch (ValidationException $e) {
                // ⚠ SAL-007: the action's `lines.{i}.*` keys use its own
                // numbering; put each back on its form row. The form renders
                // quantity, unit_price and discount under a row, so a refusal
                // about the challan line itself ("out with a courier",
                // "different customer") or the override reason goes under the
                // quantity, where it was invisible before (the deliver rule,
                // audit #1, 21 Sep 2026).
                throw ValidationException::withMessages(collect($e->errors())
                    ->mapWithKeys(function (array $messages, string $key) use ($formRow) {
                        if (preg_match('/^lines\.(\d+)\.(\w+)$/', $key, $match) === 1) {
                            $field = in_array($match[2], ['quantity', 'unit_price', 'discount'], true) ? $match[2] : 'quantity';

                            return ['lines.'.($formRow[(int) $match[1]] ?? $match[1]).'.'.$field => $messages];
                        }

                        return [$key => $messages];
                    })->all());
            }
        } catch (NoSellingPriceException $e) {
            // ⚠ C-SLS-E1. The message names the product and the date it looked on.
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            // ⚠ JournalException too (bug hunt 16 Oct 2026): the global net puts
            // it on `ledger`, which this form never renders.
            throw ValidationException::withMessages(['invoice_date' => $e->getMessage()]);
        }

        return redirect()->route('sales.invoices.show', $invoice)
            ->with('success', "Invoice {$invoice->invoice_number} raised.");
    }

    /**
     * The price of a challan line billed without an order: the resolver at
     * the invoice date, except for the proprietor's own account (D-B, 18 Oct
     * 2026), whose price is the cost of the batches the challan actually took
     * (the goods already left, so the FIFO head on the shelf is the wrong one).
     *
     * @throws NoSellingPriceException
     */
    private function unorderedPrice(ResolveSellingPrice $resolver, DeliveryLine $deliveryLine, Customer $customer, string $quantity, mixed $invoiceDate): string
    {
        if ($customer->isOwner() && ($cost = $deliveryLine->unitCostTaken()) !== null) {
            return $cost;
        }

        return $resolver->resolve($deliveryLine->product, $customer, $quantity, $invoiceDate)->unitPrice;
    }

    public function show(Request $request, SalesInvoice $invoice): Response
    {
        // ⚠ SAL-004 (audit 14 Oct 2026): what the goods cost is for cost
        // readers only (sales.reports.costs), as on the margin report.
        $seesCost = CostVisibility::allows($request->user());

        $invoice->load([
            'customer',
            'lines.product:id,name,sku',
            'lines.deliveryLine.delivery:id,delivery_number,delivered_at',
            // The counter's price trail: who changed a price or gave a discount.
            'lines.overriddenBy:id,name',
            'discountGivenBy:id,name',
            'allocations.receipt.moneyAccount:id,name',
        ]);

        $journal = Journal::query()
            ->where('source_type', $invoice->getMorphClass())
            ->where('source_id', $invoice->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        if (! $seesCost) {
            $invoice->lines->each->makeHidden('cost_released');
        }

        // ⚠ D-B (18 Oct 2026): on a sale to the proprietor at cost, the
        // reference price and the "batch cost" trail are for the Owner and the
        // cost readers; everybody else sees the price only. Blanked in memory
        // for this page, never saved.
        if ($invoice->is_owner_sale && ! OwnerPriceVisibility::allows($request->user())) {
            $invoice->lines->each(fn ($line) => $line->setAttribute('list_price', null)->setAttribute('override_reason', null)->setAttribute('price_overridden', false));
        }

        return Inertia::render('sales/invoices/show', [
            // PR5 (18 Oct 2026): what this came from, what came of it, and the journals.
            'related' => RelatedDocuments::for($invoice, request()->user()),
            // ⚠ Dates as Dhaka dates, the print page's lesson: handed over inside
            // the model they serialise as UTC timestamps, and the page sliced
            // those, showing an invoice and its payments a day early.
            'invoice' => array_merge($invoice->toArray(), [
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'allocations' => $invoice->allocations->map(fn ($allocation) => array_merge($allocation->toArray(), [
                    'receipt' => $allocation->receipt === null ? null : array_merge($allocation->receipt->toArray(), [
                        'received_on' => $allocation->receipt->received_on?->toDateString(),
                    ]),
                ]))->all(),
            ]),
            // The delivery a return against this invoice would come back on -
            // the invoice page is where a counter return naturally starts.
            // ⚠ P18 (practice 18 Oct 2026): a staff purchase has no return path
            // (the office order; the server refuses it, RecordSalesReturn), so the
            // link is not offered. An owner sale follows the normal returns.
            'returnDeliveryId' => $invoice->is_staff_sale ? null : $invoice->lines
                ->pluck('deliveryLine.delivery.id')
                ->filter()
                ->unique()
                ->first(),
            'outstanding' => $invoice->outstanding()->toTaka(),
            'received' => $invoice->receivedTotal()->toTaka(),
            'costReleased' => $seesCost ? $invoice->costReleased()->toTaka() : null,
            'isSettled' => $invoice->isSettled(),
            // ⚠ The journal is shown on the invoice on purpose. "Show me what
            // this did to the books" is the question that makes an accountant
            // trust the system, and hiding it behind the ledger screen is how
            // people stop checking.
            'journal' => CostVisibility::journal($journal, $request->user()),
            // ⚠ Only accounts the receipt can be booked into. One with no ledger
            // head was offered here - and, sorting first, preselected - and then
            // failed on submit (audit #2, 21 Sep 2026).
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            // ⭐ Corrections wave 1 (20 Oct 2026): Credit note, Correct (Void
            // sale on a counter sale) as the server allows them; Price credit
            // and Extra charge beside them; what was issued against it.
            'corrections' => [
                'actions' => app(SalesInvoiceActionPolicy::class)->actionsFor($invoice, $request->user())->toArray()['actions'],
                'counter_sale' => SalesInvoiceActionPolicy::isCounterSale($invoice),
                // ⭐ 24 Oct 2026: Price credit and Extra charge go through the
                // corrections engine too (preview, Tk 20,000, closed month):
                // their buttons and blockers, or null when not this person's.
                'price_credit' => app(PriceCreditPolicy::class)->actionsFor($invoice, $request->user())->toArray()['actions'][0] ?? null,
                'extra_charge' => app(ExtraChargePolicy::class)->actionsFor($invoice, $request->user())->toArray()['actions'][0] ?? null,
                'reversed' => $invoice->isReversed(),
                'reversal' => $invoice->reversalSummary(),
                'correction_of' => CorrectionChain::correctionOfLabel($invoice),
                'corrected_to' => CorrectionChain::linkAsOriginal($invoice)?->successor_number,
                'corrected_to_id' => CorrectionChain::linkAsOriginal($invoice)?->successor_id,
                'extra_charge_on' => $invoice->debitNoteFor?->only(['id', 'invoice_number']),
                'credit_notes' => $invoice->creditNotes()->orderBy('id')->get()->map(fn (SalesCreditNote $note) => [
                    'number' => $note->credit_note_number,
                    'kind' => $note->kind,
                    'date' => $note->credit_date->toDateString(),
                    'amount' => $note->money()->toTaka(),
                    'reason' => $note->reason,
                ])->values(),
                'extra_charges' => $invoice->debitNotes()->orderBy('id')->get(['id', 'invoice_number', 'invoice_date', 'total', 'reversed_at'])
                    ->map(fn (SalesInvoice $extra) => ['id' => $extra->id, 'number' => $extra->invoice_number, 'amount' => Money::ofTaka((string) $extra->total)->toTaka(), 'credited' => $extra->reversed_at !== null])->values(),
                'reversal_reasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'correction_reasons' => Reason::query()->where('context', 'correction')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'today' => now()->toDateString(),
            ],
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

        // Owner, 17 Oct 2026: the batch number and expiry each line took, read
        // through the challan line it bills.
        $batches = DeliveredBatches::forLines($invoice->lines->pluck('delivery_line_id'));

        $outstanding = $invoice->outstanding()->toTaka();
        $received = $invoice->receivedTotal()->toTaka();

        return Inertia::render('sales/invoices/print', [
            // ⛔⛔ SHAPED HERE, NOT HANDED OVER AS A MODEL. Passing the model
            // serialised `invoice_date` as a Carbon ISO-8601 string, so the
            // printed invoice read "2026-09-04T18:00:00.000000Z" for an invoice
            // dated the 5th - an unreadable timestamp AND a day early, because
            // the cast renders in UTC while the date is a Dhaka date. A
            // commercial document showing the wrong date is not a styling
            // problem. Dates are formatted once, here, in words that cannot be
            // misread by a reader in any timezone.
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->format('d M Y'),
                'due_date' => $invoice->due_date?->format('d M Y'),
                'is_overdue' => $invoice->due_date !== null
                    && $invoice->due_date->isPast()
                    && $invoice->outstanding()->isPositive(),
                'subtotal' => (string) $invoice->subtotal,
                'invoice_discount' => (string) $invoice->invoice_discount,
                'tax_total' => (string) $invoice->tax_total,
                'total' => (string) $invoice->total,
                // ⭐ What a tax invoice must say (VAT plan D10): the seller's BIN,
                // the value excluding VAT, the rate and the VAT per line, and
                // whether the prices shown already contained the VAT. All read
                // from what was FROZEN on the invoice, never from today's
                // settings, so a reprint years later says what it said.
                'vat' => [
                    'taxed' => $invoice->taxTotal()->minor > 0,
                    'bin' => Business::query()->whereKey($invoice->business_id)->value('vat_bin'),
                    'prices_include_tax' => (bool) $invoice->prices_include_tax,
                    'value_excluding_vat' => Money::ofMinor(Money::ofTaka((string) $invoice->total)->minor - $invoice->taxTotal()->minor)->toTaka(),
                ],
                'note' => $invoice->note,
                'customer' => $invoice->customer === null ? null : [
                    'name' => $invoice->customer->name,
                    'code' => $invoice->customer->code,
                    'address' => $invoice->customer->address,
                    'phone' => $invoice->customer->phone,
                    'email' => $invoice->customer->email,
                ],
                'lines' => $invoice->lines->values()->map(fn ($line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'line_discount' => (string) $line->line_discount,
                    'line_net' => (string) $line->line_net,
                    'tax_rate' => (string) $line->tax_rate,
                    'tax_amount' => (string) $line->tax_amount,
                    'product' => $line->product === null ? null : [
                        'name' => $line->product->name,
                        'sku' => $line->product->sku,
                    ],
                    'batches' => $line->delivery_line_id === null ? [] : ($batches[$line->delivery_line_id] ?? []),
                ])->all(),
            ],
            'outstanding' => $outstanding,
            'received' => $received,
            // ⭐ The oldest anti-tampering device on a commercial document: a
            // figure can have a digit added in front of it, a sentence cannot.
            'total_in_words' => AmountInWords::taka($invoice->total),
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
    /**
     * The counter slip, drawn on whatever roll this outlet has.
     *
     * ⭐⭐ NOTHING HERE KNOWS WHAT A PRINTER IS. The controller describes the
     * document and asks ThermalProfile what the paper is; the component draws
     * one on the other. Buy a different machine, edit one row in Settings >
     * Printers, and this method does not change.
     *
     * ⚠ THE OUTLET IS FOUND THROUGH THE DELIVERY. An invoice has no warehouse
     * of its own - the goods leaving does. So the profile is resolved from the
     * warehouse the stock actually left, and an invoice with no delivery yet
     * falls back to the business default, which is correct rather than merely
     * convenient: nothing has been handed over at any counter.
     *
     * ⚠ Dates and figures are formatted HERE. Handing Carbon to Inertia
     * serialises it as a UTC ISO string, which printed the wrong day on the
     * invoice document on 7 September - a document must never be handed a value
     * it has to interpret.
     */
    public function slip(SalesInvoice $invoice): Response
    {
        $invoice->load([
            'customer:id,name,phone',
            'lines.product:id,name,sku',
            'lines.deliveryLine.delivery:id,warehouse_id',
            'allocations.receipt.moneyAccount:id,name',
        ]);

        $warehouseId = $invoice->lines
            ->pluck('deliveryLine.delivery.warehouse_id')
            ->filter()
            ->first();

        $profile = ThermalProfile::resolve($warehouseId === null ? null : (int) $warehouseId);

        $soldBy = User::query()->where('id', $invoice->created_by)->value('name');
        $outstanding = $invoice->outstanding()->toTaka();

        // ⚠ A walk-in customer is not named on the paper. The row exists so the
        // books balance, but printing "Walk-in customer" on a receipt tells the
        // person holding it nothing they did not already know.
        $named = $invoice->customer !== null
            && ! str_contains(strtolower((string) $invoice->customer->name), 'walk-in');

        $document = new PrintableDocument(
            title: 'Sales receipt',
            number: (string) $invoice->invoice_number,
            meta: array_values(array_filter([
                ['label' => 'Date', 'value' => $invoice->invoice_date?->format('d M Y') ?? ''],
                $soldBy === null ? null : ['label' => 'Sold by', 'value' => (string) $soldBy],
            ])),
            party: $named ? [
                'heading' => 'Customer',
                'name' => (string) $invoice->customer->name,
                'lines' => array_values(array_filter([$invoice->customer->phone])),
            ] : null,
            lines: $invoice->lines->map(fn ($line) => [
                'description' => (string) ($line->product?->name ?? $line->description ?? 'Item'),
                'detail' => $line->product?->sku,
                'quantity' => DocumentMoney::quantity($line->quantity),
                'unit_price' => DocumentMoney::amount($line->unit_price),
                'discount' => (float) $line->line_discount > 0 ? DocumentMoney::amount($line->line_discount) : null,
                'net' => DocumentMoney::amount($line->line_net),
            ])->values()->all(),
            totals: array_values(array_filter([
                ['label' => 'Subtotal', 'value' => DocumentMoney::amount($invoice->subtotal), 'emphasis' => false],
                (float) $invoice->invoice_discount > 0
                    ? ['label' => 'Discount', 'value' => '-'.DocumentMoney::amount($invoice->invoice_discount), 'emphasis' => false]
                    : null,
                ['label' => 'TOTAL', 'value' => 'BDT '.DocumentMoney::amount($invoice->total), 'emphasis' => true],
                (float) $outstanding > 0
                    ? ['label' => 'DUE', 'value' => 'BDT '.DocumentMoney::amount($outstanding), 'emphasis' => true]
                    : null,
            ])),
            payments: $invoice->allocations->map(fn ($allocation) => [
                'label' => 'Paid ('.($allocation->receipt?->moneyAccount?->name ?? 'cash').')',
                'value' => DocumentMoney::amount($allocation->amount),
            ])->values()->all(),
            barcodeValue: (string) $invoice->invoice_number,
            footerLines: [
                'Thank you for shopping with us.',
                'Keep this slip for returns or exchange.',
            ],
        );

        return Inertia::render('sales/invoices/slip', [
            'invoiceId' => $invoice->id,
            'invoiceNumber' => (string) $invoice->invoice_number,
            'document' => $document->toArray(),
            'profile' => $profile->toArray(),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'barcode' => $profile->barcode((string) $invoice->invoice_number),
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
            'salesOrder:id,order_number',
        ]);

        $payload = $receipt->toArray();

        // ⚠ The invoice date as a Dhaka date. Inside the model it serialises as a
        // UTC timestamp and the page's slice printed the day before.
        foreach ($receipt->allocations as $index => $allocation) {
            if ($allocation->invoice !== null) {
                $payload['allocations'][$index]['invoice']['invoice_date'] = $allocation->invoice->invoice_date?->toDateString();
            }
        }

        $held = $receipt->sales_order_id === null ? null : $receipt->unallocated();

        return Inertia::render('sales/receipts/print', [
            'receipt' => $payload,
            // ⭐ Money paid for an order that no invoice has taken yet (SSLCommerz
            // slice P1). Without this line the paper would state an amount and
            // settle nothing, which reads as money gone missing.
            'heldFor' => $held !== null && $held->minor > 0
                ? ['id' => $receipt->salesOrder?->id, 'order_number' => $receipt->salesOrder?->order_number, 'amount' => $held->toTaka()]
                : null,
            // ⚠⚠ Formatted here, not sliced on the page. The page was taking the
            // first ten characters of what Inertia serialised, which is a UTC
            // ISO string - so a receipt taken after 6am Dhaka on the 1st printed
            // the 31st. Identical to the invoice-date defect fixed on 7 Sep.
            'receivedOn' => $receipt->received_on?->format('d M Y'),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    /**
     * The same money receipt on a till roll.
     *
     * ⚠ A receipt may settle SEVERAL invoices at once, so each one is a line on
     * the paper rather than the paper being about any single bill - the same
     * rule the A4 version follows, kept here so the two cannot disagree.
     *
     * ⚠ No warehouse is involved in taking money, so this always resolves the
     * business default profile. That is correct: a payment can be taken at a
     * desk that sells nothing.
     */
    public function receiptSlip(CustomerReceipt $receipt): Response
    {
        $receipt->load([
            'customer:id,name,phone',
            'moneyAccount:id,name,type',
            'allocations.invoice:id,invoice_number,invoice_date,total',
            'salesOrder:id,order_number',
        ]);

        $profile = ThermalProfile::resolve(null);
        $receivedBy = User::query()->where('id', $receipt->created_by)->value('name');

        $document = new PrintableDocument(
            title: 'Money receipt',
            number: 'No. '.$receipt->id,
            meta: array_values(array_filter([
                ['label' => 'Date', 'value' => $receipt->received_on?->format('d M Y') ?? ''],
                ['label' => 'Through', 'value' => (string) ($receipt->moneyAccount?->name ?? 'cash')],
                $receivedBy === null ? null : ['label' => 'Received by', 'value' => (string) $receivedBy],
            ])),
            party: $receipt->customer === null ? null : [
                'heading' => 'Received from',
                'name' => (string) $receipt->customer->name,
                'lines' => array_values(array_filter([$receipt->customer->phone])),
            ],
            // ⚠ The allocations ARE the lines. A receipt that named one invoice
            // while carrying an amount covering three is the defect the A4
            // version was written to avoid.
            lines: $receipt->allocations->map(fn ($allocation) => [
                'description' => 'Against '.($allocation->invoice?->invoice_number ?? 'account'),
                'detail' => $allocation->invoice?->invoice_date?->format('d M Y'),
                'quantity' => '1',
                'unit_price' => DocumentMoney::amount($allocation->amount),
                'discount' => null,
                'net' => DocumentMoney::amount($allocation->amount),
            ])->values()->concat(
                // ⭐ Money paid for an order no invoice has taken yet (SSLCommerz
                // slice P1), so the lines still add up to what was received.
                $receipt->sales_order_id !== null && $receipt->unallocated()->minor > 0
                    ? [[
                        'description' => 'Paid in advance for '.($receipt->salesOrder?->order_number ?? 'an order'),
                        'detail' => null,
                        'quantity' => '1',
                        'unit_price' => DocumentMoney::amount($receipt->unallocated()->toTaka()),
                        'discount' => null,
                        'net' => DocumentMoney::amount($receipt->unallocated()->toTaka()),
                    ]]
                    : [],
            )->all(),
            totals: [
                ['label' => 'RECEIVED', 'value' => 'BDT '.DocumentMoney::amount($receipt->amount), 'emphasis' => true],
            ],
            payments: [],
            // ⛔ NO BARCODE, deliberately. The invoice slip carries one because
            // the returns screen accepts that exact string; nothing in this ERP
            // scans a receipt. A barcode nobody reads is decoration on a
            // financial document, and it takes up a third of the roll.
            barcodeValue: null,
            footerLines: ['Thank you.'],
        );

        return Inertia::render('sales/receipts/slip', [
            'receiptId' => $receipt->id,
            'receiptNumber' => 'No. '.$receipt->id,
            'document' => $document->toArray(),
            'profile' => $profile->toArray(),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'barcode' => null,
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
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ], [
            'received_on.before_or_equal' => 'Money cannot be received on a future date.',
        ]);

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        // ⚠⚠ Two ordinary refusals used to arrive as a blank 500 here: money
        // into an account with no ledger head, and money dated into a month
        // somebody has closed (audit #2, 21 Sep 2026). Both already carried a
        // sentence; nothing caught them, and bootstrap/app.php renders neither.
        // Order payments had caught both all along. Each lands on the field the
        // person can change: the date, or the account.
        try {
            $action->handle(
                customer: $invoice->customer,
                moneyAccount: $moneyAccount,
                receivedOn: $data['received_on'],
                allocations: [['invoice' => $invoice, 'amount' => (string) $data['amount']]],
                createdBy: $request->user(),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        } catch (MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($moneyAccount, $e)]);
        }

        return back()->with('success', 'Receipt recorded.');
    }
}
