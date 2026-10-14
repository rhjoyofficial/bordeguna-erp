<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What the customer owes, and what the business earned.
 *
 * ⚠ For a counter sale this is raised in the same transaction as the delivery,
 * so Goods Delivered Not Invoiced nets to zero instantly. For a distributor it
 * arrives weeks later. Nothing here knows which.
 */
class SalesInvoice extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'customer_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'invoice_discount',
        'tax_total',
        'total',
        'is_opening',
        'note',
        'created_by',
    ];

    protected $attributes = [
        'subtotal' => 0,
        'invoice_discount' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:4',
            'invoice_discount' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'tax_point_date' => 'date:Y-m-d',
            'prices_include_tax' => 'boolean',
            'total' => 'decimal:4',
            'is_opening' => 'boolean',
        ];
    }

    /**
     * Invoices and bills made IN the ERP, leaving out the opening ones (money
     * owed from before cutover, finance decision A4/A5). Every sales or
     * purchases figure reads through this; what is owed (ageing, statements,
     * outstanding) deliberately does not.
     */
    public function scopeTrading(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.is_opening', false);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'customer_id', 'invoice_date', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who allowed the discount on the whole bill at the counter (21 Sep 2026). */
    public function discountGivenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_given_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function totalMoney(): Money
    {
        return Money::ofTaka((string) $this->total);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class, 'sales_invoice_id');
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnAllocation::class, 'sales_invoice_id');
    }

    /**
     * ⚠ DERIVED from the allocations, never stored on the invoice. A stored
     * "amount paid" is a second answer to a question the allocations already
     * answer, and it drifts the first time a receipt is corrected. Same
     * reasoning as SupplierBill.
     */
    public function receivedTotal(): Money
    {
        return Money::ofTaka((string) ($this->allocations()->sum('amount') ?: '0'));
    }

    /**
     * What sales returns have knocked off this invoice - the mirror of
     * SupplierBill::creditedTotal().
     */
    public function creditedTotal(): Money
    {
        return Money::ofTaka((string) ($this->creditAllocations()->sum('amount') ?: '0'));
    }

    /** Write-offs, discounts and set-offs allocated to this invoice (29 Sep 2026). */
    public function adjustmentAllocations(): HasMany
    {
        return $this->hasMany(PartyAdjustmentAllocation::class, 'sales_invoice_id');
    }

    public function adjustedTotal(): Money
    {
        return Money::ofTaka((string) ($this->adjustmentAllocations()->sum('amount') ?: '0'));
    }

    public function outstanding(): Money
    {
        return Money::ofMinor(
            $this->totalMoney()->minor
                - $this->receivedTotal()->minor
                - $this->creditedTotal()->minor
                - $this->adjustedTotal()->minor,
        );
    }

    public function isSettled(): bool
    {
        return $this->outstanding()->minor <= 0;
    }

    /**
     * Invoices that still owe money - isSettled() asked in SQL.
     *
     * ⚠⚠ SO A LIST CAN FILTER BEFORE IT PAGINATES. The invoice list used to
     * paginate twenty rows and then drop the settled ones in PHP, so "Unpaid"
     * showed whichever of the latest twenty happened to be unpaid and hid every
     * older debt behind a pager that counted the wrong thing (Sales UX audit
     * #12, 21 Sep 2026). "Who owes us money" is the one question that list
     * exists to answer.
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereRaw(self::outstandingPaisaSql($query).' > 0');
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereRaw(self::outstandingPaisaSql($query).' <= 0');
    }

    /**
     * outstanding() for each row as `outstanding_paisa`, in the same query.
     *
     * ⚠ SAL-009 (audit 14 Oct 2026): Receive payment called outstanding() on
     * every invoice the customer ever had, three SUM queries each, twice. One
     * column on the one query answers the same question.
     */
    public function scopeWithOutstandingPaisa(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->selectRaw(self::outstandingPaisaSql($query).' as outstanding_paisa');
    }

    /**
     * outstanding() in paisa, as an SQL expression over one invoice row.
     *
     * ⚠ Each figure is rounded to whole paisa exactly where outstanding() rounds
     * it (the total, the sum received, the sum credited, each through
     * Money::ofTaka), so the list and the invoice page cannot disagree about an
     * invoice a fraction of a paisa either side of paid. ROUND on a MySQL
     * DECIMAL is half away from zero, the same rule as Money::ofTaka.
     */
    private static function outstandingPaisaSql(Builder $query): string
    {
        $invoices = $query->getModel()->getTable();
        $receipts = (new CustomerReceiptAllocation)->getTable();
        $credits = (new SalesReturnAllocation)->getTable();
        $adjusted = (new PartyAdjustmentAllocation)->getTable();

        return "(ROUND({$invoices}.total * 100)"
            ." - COALESCE((SELECT ROUND(SUM(r.amount) * 100) FROM {$receipts} r WHERE r.sales_invoice_id = {$invoices}.id), 0)"
            ." - COALESCE((SELECT ROUND(SUM(c.amount) * 100) FROM {$credits} c WHERE c.sales_invoice_id = {$invoices}.id), 0)"
            ." - COALESCE((SELECT ROUND(SUM(a.amount) * 100) FROM {$adjusted} a WHERE a.sales_invoice_id = {$invoices}.id), 0))";
    }

    /**
     * The delivery charges this invoice bills for the orders it covers, which
     * credit 4300 Delivery Charges Income rather than sales revenue.
     */
    public function deliveryChargeTotal(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, SalesInvoiceLine $line) => $line->isDeliveryCharge()
                ? $carry->plus(Money::ofTaka((string) $line->line_net))
                : $carry,
            Money::zero(),
        );
    }

    /**
     * The subtotal less its delivery charges: what a whole-invoice discount is
     * taken from, what revenue is credited with, and what a return pro-rates the
     * discount over. ⚠ A coupon was agreed on goods, so none of it belongs to a
     * delivery line, and attributing a share there would let a full return
     * refund more than the customer paid for the goods.
     */
    public function goodsSubtotal(): Money
    {
        return Money::ofMinor(Money::ofTaka((string) $this->subtotal)->minor - $this->deliveryChargeTotal()->minor);
    }

    // ── VAT: what the ledger is credited and debited, NET of VAT ──────────
    //
    // ⚠⚠ With VAT off every one of these equals the figure beside it above, to
    // the paisa, so the journal of an untaxed invoice has not changed at all.
    // With VAT on and prices INCLUSIVE, `line_net`, `subtotal` and the discount
    // all still contain the VAT - they are what the customer sees - and these
    // are what is left once it is taken out. See ApplyInvoiceTax for why the
    // VAT inside the discount is kept separately.

    public function taxTotal(): Money
    {
        return Money::ofTaka((string) $this->tax_total);
    }

    /** Goods revenue excluding VAT: what 4101 Product Sales (under Sales Revenue 4100) is credited. */
    public function goodsRevenueExTax(): Money
    {
        return Money::ofMinor($this->goodsSubtotal()->minor - $this->taxInside(delivery: false, withDiscount: true)->minor);
    }

    /** The delivery charge excluding VAT: what 4300 is credited. */
    public function deliveryExTax(): Money
    {
        return Money::ofMinor($this->deliveryChargeTotal()->minor - $this->taxInside(delivery: true, withDiscount: false)->minor);
    }

    /** The whole-invoice discount excluding VAT: what 4210 is debited. */
    public function discountExTax(): Money
    {
        $inside = $this->lines->reduce(
            fn (Money $carry, SalesInvoiceLine $line) => $carry->plus(Money::ofTaka((string) $line->tax_on_discount)),
            Money::zero(),
        );

        return Money::ofMinor(Money::ofTaka((string) $this->invoice_discount)->minor - $inside->minor);
    }

    /**
     * The VAT sitting INSIDE the stored figures. Zero unless the line's price
     * included VAT: an exclusive line's `line_net` never contained any.
     */
    private function taxInside(bool $delivery, bool $withDiscount): Money
    {
        return $this->lines
            ->filter(fn (SalesInvoiceLine $line) => $line->isDeliveryCharge() === $delivery && $line->prices_include_tax)
            ->reduce(
                fn (Money $carry, SalesInvoiceLine $line) => $carry
                    ->plus(Money::ofTaka((string) $line->tax_amount))
                    ->plus($withDiscount ? Money::ofTaka((string) $line->tax_on_discount) : Money::zero()),
                Money::zero(),
            );
    }

    /**
     * The cost this invoice released from GDNI into Cost of Goods Sold.
     */
    public function costReleased(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, SalesInvoiceLine $line) => $carry->plus(Money::ofTaka((string) $line->cost_released)),
            Money::zero(),
        );
    }
}
