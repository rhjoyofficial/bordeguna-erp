<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
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
        'channel',
        'order_number',
        'shop_reference',
        'ordered_at',
        'expected_date',
        'delivery_charge',
        'payment_method',
        'status',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'channel' => SalesChannel::class,
            'ordered_at' => 'date',
            'expected_date' => 'date',
            // ⚠ A STRING, kept at two places, for the reason slice 2.3 measured
            // the hard way: MySQL returns "60.00" for a decimal and SQLite
            // returns "60", the suite runs on SQLite and production is MySQL, so
            // an uncast money comparison behaves differently in production than
            // in every test that guards it.
            'delivery_charge' => 'decimal:2',
        ];
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
            ->logOnly(['status', 'order_number', 'customer_id'])
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
     * Where the goods go. ⚠ A HasOne and not columns on this table: a counter
     * sale has no destination and never will, and nullable columns would make
     * "there is no address" and "nobody filled the address in" the same shape.
     */
    public function address(): HasOne
    {
        return $this->hasOne(SalesOrderAddress::class, 'sales_order_id');
    }

    /**
     * What the customer is quoted in total: goods at the agreed prices, plus
     * the agreed delivery charge.
     *
     * ⚠⚠ COMPOSED FROM THE STORED AGREEMENT, never re-resolved. Every figure
     * here was frozen when the order was taken, which is the whole reason those
     * columns exist - a price list corrected next week must not change what
     * this order says the customer owes.
     *
     * ⛔ It is NOT an invoice total and must not be used as one. The delivery
     * charge has no account mapping yet, so nothing in Accounting reads this.
     */
    public function agreedTotal(): string
    {
        return bcadd($this->goodsTotal(), (string) ($this->delivery_charge ?? '0'), 2);
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
     * May goods still leave against this order? Mirrors PO::isReceivable():
     * approved, and not yet fully delivered, closed or cancelled.
     */
    public function isDeliverable(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved
            && in_array($this->status, [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered], true);
    }
}
