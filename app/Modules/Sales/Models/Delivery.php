<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\ConsignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Goods leaving the warehouse for a customer.
 *
 * ⚠ A retail sale and a distributor challan are the SAME document. The counter
 * raises the invoice in the same transaction; the distributor raises it weeks
 * later. Nothing here branches on which.
 */
class Delivery extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'customer_id',
        'warehouse_id',
        'delivery_number',
        'delivered_at',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['delivery_number', 'customer_id', 'warehouse_id', 'delivered_at'])
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
        return $this->hasMany(DeliveryLine::class);
    }

    /**
     * The parcel, when this challan went out with a courier.
     */
    public function consignment(): HasOne
    {
        return $this->hasOne(Consignment::class);
    }

    /**
     * A booked consignment holds the challan's invoice and any return until the
     * courier reports back. Null when the challan is free to bill by hand.
     */
    public function openConsignment(): ?Consignment
    {
        return Consignment::query()
            ->where('delivery_id', $this->id)
            ->where('status', ConsignmentStatus::Booked)
            ->with('courier:id,name')
            ->first();
    }

    /**
     * What the goods on this challan cost, at the FIFO layers actually taken.
     *
     * ⚠⚠ Summed from the MOVEMENTS, never from a price or an average. This is
     * the number that becomes cost of goods sold, and it is the reason the whole
     * module exists in this shape. Anything else is a second opinion about what
     * the stock cost.
     */
    public function costValue(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, DeliveryLine $line) => $carry->plus($line->costValue()),
            Money::zero(),
        );
    }
}
