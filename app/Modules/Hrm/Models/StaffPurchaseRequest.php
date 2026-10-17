<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A Staff Product Benefit application (office order BDG/HR/09/01/2026),
 * priced at the cost of the FIFO batch reserved at approval (Phase 2,
 * ChatGPT ruling Q1, 17 Oct 2026).
 *
 * States: pending -> approved -> fulfilled; pending -> rejected; approved ->
 * cancelled (reservation released); approved -> needs_reapproval (the reserved
 * batch left or its cost changed before the handover) -> approved again with
 * the new price. Rejected and cancelled never count against the allowance.
 */
class StaffPurchaseRequest extends Model
{
    use BelongsToBusiness;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const NEEDS_REAPPROVAL = 'needs_reapproval';

    public const FULFILLED = 'fulfilled';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** The states that still hold or will hold the month's allowance. */
    public const COUNTING = [self::PENDING, self::APPROVED, self::NEEDS_REAPPROVAL, self::FULFILLED];

    public const PRICE_BASIS_FIFO_RESERVED = 'fifo_reserved';

    protected $fillable = [
        'business_id',
        'number',
        'employee_id',
        'warehouse_id',
        'customer_id',
        'payment_mode',
        'status',
        'price_basis',
        'note',
        'decided_by',
        'decided_at',
        'decision_note',
        'reapproval_reason',
        'fulfilled_by',
        'fulfilled_at',
        'sales_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StaffPurchaseLine::class, 'request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StaffPurchaseEvent::class, 'request_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(StaffPurchaseReplacement::class, 'request_id');
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reference');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function scopeCounting(Builder $query): Builder
    {
        return $query->whereIn('status', self::COUNTING);
    }

    public function isSalaryDeduction(): bool
    {
        return $this->payment_mode === 'salary_deduction';
    }

    /** Write one audit line (plan E7, Audit tab). */
    public function record(string $action, ?User $actor, array $details = []): StaffPurchaseEvent
    {
        return $this->events()->create([
            'business_id' => $this->business_id,
            'action' => $action,
            'actor_id' => $actor?->id,
            'details' => $details === [] ? null : $details,
            'created_at' => now(),
        ]);
    }
}
