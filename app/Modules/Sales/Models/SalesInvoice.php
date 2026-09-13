<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
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
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'invoice_discount' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
        ];
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

    public function outstanding(): Money
    {
        return Money::ofMinor(
            $this->totalMoney()->minor
                - $this->receivedTotal()->minor
                - $this->creditedTotal()->minor,
        );
    }

    public function isSettled(): bool
    {
        return $this->outstanding()->minor <= 0;
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
