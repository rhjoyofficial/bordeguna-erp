<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A recorded promise: this customer has agreed to take these quantities of
 * these products at these prices.
 *
 * ⚠⚠ IT MOVES NO STOCK AND POSTS NO JOURNAL, EVER. Accounting recognises
 * events - goods leaving, revenue earned, money arriving - and a promise is
 * none of those. See SALES-ORDER-ARCHITECTURE.md §2, §7.
 *
 * ⚠ Always Approvable, mirroring PurchaseOrder (C-SO-1, Joy 2026-08-11):
 * Owner/Manager self-approve on creation, Staff-created orders sit pending
 * until reviewed, and goods may only leave against an APPROVED order.
 * Approving has no domain side effect by itself - it only makes the order
 * eligible for delivery.
 *
 * ⚠ The order is NOT required. The counter sale skips it, and a delivery with
 * no order behind it stays legal (the direct-delivery path). The "one engine"
 * rule of Phase 1, extended one document to the left.
 */
class SalesOrder extends Model
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $with = ['approvalRequest'];

    protected $fillable = [
        'business_id',
        'customer_id',
        'warehouse_id',
        'store_id',
        // Owner decision 11 Oct 2026: which brand the order is sold under.
        'brand_id',
        'channel',
        'order_number',
        'shop_reference',
        'ordered_at',
        'expected_date',
        'delivery_charge',
        'order_discount',
        'coupon_code',
        'delivery_promise_min_hours',
        'delivery_promise_max_hours',
        'payment_method',
        'status',
        'note',
        // Owner, 17 Oct 2026: for the rider and the packer, not the office.
        'special_instructions',
        'created_by',
        // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the parcel weight worked
        // out from the lines, the weight somebody typed instead (no reason
        // asked), and - silently - who typed it and when.
        'weight_grams_auto',
        'weight_grams',
        'weight_edited_by',
        'weight_edited_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'channel' => SalesChannel::class,
            'ordered_at' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            // ⚠ A STRING, kept at two places, for the reason slice 2.3 measured
            // the hard way: MySQL returns "60.00" for a decimal and SQLite
            // returns "60", the suite runs on SQLite and production is MySQL, so
            // an uncast money comparison behaves differently in production than
            // in every test that guards it.
            'delivery_charge' => 'decimal:2',
            'order_discount' => 'decimal:4',
            'delivery_promise_min_hours' => 'integer',
            'delivery_promise_max_hours' => 'integer',
            'weight_grams_auto' => 'integer',
            'weight_grams' => 'integer',
            'weight_edited_at' => 'datetime',
        ];
    }

    /**
     * The parcel weight in grams: what somebody typed, else the worked-out
     * weight. ⚠ Null when neither is known - never zero.
     */
    public function effectiveWeightGrams(): ?int
    {
        return $this->weight_grams ?? $this->weight_grams_auto;
    }

    /**
     * ⚠ Null in the column means Internal - an order somebody typed in, which
     * is every order that predates the website. Read through here rather than
     * from the attribute so a screen never has to know that.
     *
     * ⚠ Deliberately NOT named `channel()`. A method whose name matches an
     * attribute is only ever reached when that attribute is absent from the
     * loaded row, and Eloquent then insists it return a relationship - so a
     * `select` that omitted the column would turn this into a LogicException
     * rather than the fallback it is here for.
     */
    public function effectiveChannel(): SalesChannel
    {
        return $this->channel ?? SalesChannel::Internal;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'order_number', 'customer_id', 'weight_grams'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    /**
     * The shop this order was placed on, or null for an order typed into the
     * ERP. One of the two dimensions D17 asked for.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The brand the order is sold under (Joy, 11 Oct 2026): LaLaDia, Bionic-Garden.
     * Null on an order taken before brands were recorded, or from a multi-brand store.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Where the goods go. ⚠ A HasOne and not columns on this table: a counter
     * sale has no destination and never will, and nullable columns would make
     * "there is no address" and "nobody filled the address in" the same shape.
     */
    public function address(): HasOne
    {
        return $this->hasOne(SalesOrderAddress::class, 'sales_order_id');
    }

    /**
     * What the customer is quoted in total: goods at the agreed prices, less any
     * discount agreed on the whole order (a coupon, slice 2.5b), plus the agreed
     * delivery charge.
     *
     * ⚠⚠ COMPOSED FROM THE STORED AGREEMENT, never re-resolved. Every figure
     * here was frozen when the order was taken, which is the whole reason those
     * columns exist - a price list corrected next week must not change what
     * this order says the customer owes.
     *
     * ⛔ It is NOT an invoice total and must not be used as one. Invoices bill
     * the goods they cover; the delivery charge rides on the first of them.
     */
    public function agreedTotal(): string
    {
        return bcadd(
            bcsub($this->goodsTotal(), $this->orderDiscount()->toTaka(), 2),
            (string) ($this->delivery_charge ?? '0'),
            2,
        );
    }

    /**
     * The delivery window this order was promised, as a sentence, or null for an
     * order that promised none (every order typed into the ERP).
     */
    public function deliveryPromise(): ?string
    {
        if ($this->delivery_promise_max_hours === null) {
            return null;
        }

        return Store::promiseSentence((int) $this->delivery_promise_min_hours, (int) $this->delivery_promise_max_hours);
    }

    /**
     * A discount agreed on the whole order - a coupon (slice 2.5b). ⚠ Kept apart
     * from the goods total because it is billed on the invoice as a whole-invoice
     * discount and posts to 4210 Discounts Allowed (Joy, 13 September), while a
     * line discount reduces the line.
     */
    public function orderDiscount(): Money
    {
        return Money::ofTaka((string) ($this->order_discount ?? '0'));
    }

    public function deliveryCharge(): Money
    {
        return Money::ofTaka((string) ($this->delivery_charge ?? '0'));
    }

    /**
     * Has an invoice already billed this order's delivery charge?
     *
     * ⚠ Read under the caller's lock on the order when it decides billing; the
     * unique index on `billed_delivery_for_order_id` is the backstop.
     */
    public function deliveryChargeBilled(): bool
    {
        return SalesInvoiceLine::query()->where('billed_delivery_for_order_id', $this->id)->exists();
    }

    /**
     * How much of each invoice's whole-invoice discount came from this order.
     */
    public function invoiceDiscountShares(): HasMany
    {
        return $this->hasMany(SalesInvoiceOrderDiscount::class, 'sales_order_id');
    }

    /**
     * Has every line been invoiced in full?
     *
     * ⚠ Reads what the delivery lines record, so it is only true once goods
     * have left AND been billed - an order closed short never finishes, and its
     * unbilled discount is billed by nothing, which is correct: nothing is owed.
     */
    public function isFullyInvoiced(): bool
    {
        foreach ($this->lines as $line) {
            $invoiced = (float) $line->deliveryLines->sum('quantity_invoiced');

            if ($invoiced + 0.00005 < (float) $line->quantity_ordered) {
                return false;
            }
        }

        return $this->lines->isNotEmpty();
    }

    /**
     * The share of this order's agreed order discount that an invoice billing
     * $netBilled of its goods should carry.
     *
     * ⚠⚠ THE INVOICE THAT FINISHES THE ORDER BILLS THE REMAINDER, never another
     * rounded share - the rule a line's discount and a delivery line's cost
     * already follow, for the same reason. Every other invoice bills the order
     * discount in proportion to the goods it covers, in integer paisa.
     */
    public function orderDiscountToBill(Money $netBilled, bool $finishesOrder): Money
    {
        $discount = $this->orderDiscount();

        if ($discount->isZero()) {
            return Money::zero();
        }

        $billed = Money::ofTaka((string) ($this->invoiceDiscountShares()->sum('amount') ?: '0'));
        $unbilled = max(0, $discount->minor - $billed->minor);

        if ($finishesOrder) {
            return Money::ofMinor($unbilled);
        }

        $goods = Money::ofTaka($this->goodsTotal())->minor;

        if ($goods <= 0) {
            return Money::zero();
        }

        $product = $discount->minor * $netBilled->minor;
        $share = intdiv($product, $goods);

        if (($product % $goods) * 2 >= $goods) {
            $share++;
        }

        return Money::ofMinor(min($share, $unbilled));
    }

    /**
     * The goods at their agreed prices, net of any discount agreed per line.
     *
     * ⚠ EACH LINE IS ROUNDED BEFORE THEY ARE ADDED, deliberately, because that
     * is what a customer can check with a pencil: the page shows a figure per
     * line and a total under them, and a total that does not equal the column
     * above it is a document somebody stops trusting. Summing at four places
     * and rounding once at the end is defensible arithmetic that can differ
     * from the printed column by a paisa, which is the worse answer here.
     *
     * ⚠ Composed from what was agreed, never re-resolved from the price list.
     */
    public function goodsTotal(): string
    {
        // ⚠ Net of each line's agreed discount (slice 2.5a). A bundle sold for
        // less than its parts states the saving on the lines, so a total that
        // added the gross figures would be the price of the parts, not the
        // price the customer agreed.
        return $this->lines->reduce(
            fn (string $carry, SalesOrderLine $line) => bcadd($carry, $line->netTotal()->toTaka(), 2),
            '0',
        );
    }

    /**
     * Money the customer paid for this order, whether or not an invoice has
     * taken it yet (SSLCommerz slice P1).
     */
    public function advanceReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class, 'sales_order_id');
    }

    /**
     * Money paid back out of what this order held.
     */
    public function advanceRefunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class, 'sales_order_id');
    }

    /**
     * The customer's money this order is holding: paid for it, not yet taken by
     * any invoice, not paid back, and not on its way back through SSLCommerz.
     *
     * ⚠ DERIVED, never stored, for the reason an invoice's outstanding is: a
     * stored balance is a second answer to a question the documents already
     * answer, and it drifts the first time one of them is corrected.
     *
     * ⚠⚠ A refund SSLCommerz has been asked for is RESERVED here from the moment
     * it is requested until it is booked or refused (slice P4). Every reader
     * takes it: the by-hand refund cap, the gateway refund cap, and the invoice
     * that would otherwise apply money already leaving. Until it is booked the
     * ledger still shows it in 1140, which is why this differs from the ledger
     * for that time, deliberately.
     */
    public function advanceHeld(): Money
    {
        $received = Money::ofTaka((string) ($this->advanceReceipts()->sum('amount') ?: '0'));

        $allocated = Money::ofTaka((string) (CustomerReceiptAllocation::query()
            ->whereIn('customer_receipt_id', $this->advanceReceipts()->select('id'))
            ->sum('amount') ?: '0'));

        $refunded = Money::ofTaka((string) ($this->advanceRefunds()->sum('amount') ?: '0'));

        $leaving = GatewayRefund::inFlightFor($this);

        return Money::ofMinor($received->minor - $allocated->minor - $refunded->minor - $leaving->minor);
    }

    /**
     * The invoices that bill this order: its goods, or its delivery charge.
     *
     * @return Builder<SalesInvoice>
     */
    public function invoicesQuery(): Builder
    {
        $orderLineIds = SalesOrderLine::query()->where('sales_order_id', $this->id)->select('id');
        $deliveryLineIds = DeliveryLine::query()->whereIn('sales_order_line_id', $orderLineIds)->select('id');

        return SalesInvoice::query()->whereIn('id', SalesInvoiceLine::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('delivery_line_id', $deliveryLineIds)
                ->orWhere('billed_delivery_for_order_id', $this->id))
            ->select('sales_invoice_id'));
    }

    /**
     * What the customer has paid towards this order: money paid for it, less
     * what was paid back, plus money received against its invoices by any other
     * route (cash a courier collected, a receipt typed against the invoice).
     *
     * ⚠ An invoice that bills several orders counts in full for each of them, so
     * on such an order this over-states what was paid and a new payment is
     * refused sooner rather than later. Only a typed distributor invoice does
     * that; a shop's parcel bills one order.
     */
    public function paidTowards(): Money
    {
        $received = Money::ofTaka((string) ($this->advanceReceipts()->sum('amount') ?: '0'));
        // ⚠ Money on its way back through SSLCommerz is not paid towards the
        // order either, the same reservation advanceHeld() makes.
        $refunded = Money::ofTaka((string) ($this->advanceRefunds()->sum('amount') ?: '0'))
            ->plus(GatewayRefund::inFlightFor($this));

        $otherwise = Money::ofTaka((string) (CustomerReceiptAllocation::query()
            ->whereIn('sales_invoice_id', $this->invoicesQuery()->select('id'))
            ->whereNotIn('customer_receipt_id', $this->advanceReceipts()->select('id'))
            ->sum('amount') ?: '0'));

        return Money::ofMinor($received->minor - $refunded->minor + $otherwise->minor);
    }

    /**
     * What is left for the customer to pay on this order, never below zero.
     *
     * ⚠ Once nothing more will be billed - cancelled, rejected, closed short or
     * fully invoiced - it is what the order's INVOICES still owe, not the agreed
     * total less payments. A cancelled order owes nothing, and one closed short
     * owes only for what was delivered. Found on the walk: a cancelled order read
     * "still to pay 1,820.00" the moment its payment was refunded.
     */
    public function stillToPay(): Money
    {
        if ($this->isFinishedForBilling()) {
            return Money::ofMinor(max(0, $this->invoicesQuery()->get()->sum(
                fn (SalesInvoice $invoice) => $invoice->outstanding()->minor,
            )));
        }

        return Money::ofMinor(max(0, Money::ofTaka($this->agreedTotal())->minor - $this->paidTowards()->minor));
    }

    /**
     * Will nothing more be billed on this order, so that money it still holds is
     * owed back to the customer?
     *
     * ⚠ Cancelled or rejected, or: no more goods can leave against it (delivered
     * or closed short) AND everything that did leave was either invoiced or came
     * back. Not "fully invoiced" - found by a test: a prepaid parcel only partly
     * delivered leaves the order reading "delivered" (C-SR-8), it can neither be
     * closed short nor re-delivered, and under "fully invoiced" the money it did
     * not use would have sat on the order with no screen ever saying it was owed.
     * A parcel still with the courier keeps the order open until its answer.
     */
    public function isFinishedForBilling(): bool
    {
        if ($this->status === SalesOrderStatus::Cancelled || $this->approvalRequest?->status === ApprovalStatus::Rejected) {
            return true;
        }

        if (! in_array($this->status, [SalesOrderStatus::Delivered, SalesOrderStatus::Closed], true)) {
            return false;
        }

        return $this->lines()->with('deliveryLines')->get()->every(
            fn (SalesOrderLine $line) => $line->deliveryLines->every(
                fn (DeliveryLine $deliveryLine) => (float) $deliveryLine->quantityUninvoiced() <= 0.00005,
            ),
        );
    }

    /**
     * May goods still leave against this order? Mirrors PO::isReceivable():
     * approved, and not yet fully delivered, closed or cancelled.
     */
    public function isDeliverable(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved
            && in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered], true);
    }
}
