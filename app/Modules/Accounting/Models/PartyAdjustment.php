<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A write-off, discount or set-off against specific invoices and bills
 * (see the migration for the postings).
 */
class PartyAdjustment extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const KINDS = [
        'customer_write_off' => 'Bad debt written off',
        'customer_discount' => 'Discount allowed to a customer',
        'supplier_discount' => 'Discount received from a supplier',
        'set_off' => 'Set-off (customer and supplier are the same party)',
    ];

    protected $fillable = ['business_id', 'kind', 'adjusted_on', 'customer_id', 'supplier_id', 'amount', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['adjusted_on' => 'date:Y-m-d', 'amount' => 'decimal:4'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PartyAdjustmentAllocation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('party_adjustment');
    }
}
