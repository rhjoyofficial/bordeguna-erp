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
