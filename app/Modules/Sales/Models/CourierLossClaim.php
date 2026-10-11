<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\InventoryAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A parcel the courier lost or damaged, and what we claim from it (courier hub
 * slice 8, ChatGPT ruling 5).
 *
 * ⭐ The goods leave the books through the ordinary inventory adjustment flow
 * (type lost_or_stolen or damaged, FIFO cost, its approval workflow), sourced
 * on this claim. The compensation is income on 4960 Courier Compensation
 * Income naming the courier, whether it arrives on a payout statement (linked
 * by the parcel) or straight into a bank.
 */
class CourierLossClaim extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const LOST = 'lost';

    public const DAMAGED = 'damaged';

    public const KINDS = [self::LOST => 'Lost', self::DAMAGED => 'Damaged'];

    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const STATUSES = [
        self::OPEN => 'Open',
        self::ACCEPTED => 'Accepted by the courier',
        self::PAID => 'Compensation received',
        self::REJECTED => 'Rejected by the courier',
    ];

    protected $fillable = [
        'business_id',
        'consignment_id',
        'money_account_id',
        'kind',
        'reported_on',
        'value_at_cost',
        'claimed_amount',
        'compensation_received',
        'status',
        'notes',
        'sales_return_id',
        'courier_statement_line_id',
        'compensation_money_account_id',
        'compensation_on',
        'compensation_reference',
        'reported_by',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'reported_on' => 'date:Y-m-d',
            'compensation_on' => 'date:Y-m-d',
            'value_at_cost' => 'decimal:4',
            'claimed_amount' => 'decimal:4',
            'compensation_received' => 'decimal:4',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'value_at_cost', 'claimed_amount', 'compensation_received', 'status', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(CourierStatementLine::class, 'courier_statement_line_id');
    }

    public function compensationAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'compensation_money_account_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** The write-offs, one per line, each through its own approval. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class, 'source_id')->where('source_type', self::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::PAID, self::REJECTED], true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function claimed(): Money
    {
        return Money::ofTaka((string) $this->claimed_amount);
    }
}
