<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A retail seller's stock sheet (initial plan B21, R109; Joy's Q3 = A,
 * 24 Oct 2026): goods handed over to him (kind `handover`) or his unsold
 * goods taken back (kind `return`).
 *
 * ⚠ Neither is a sale. The goods stay the company's (consignment): each line
 * is a stock transfer at the batch cost between a warehouse and his seller
 * stock, and no journal is written. The Trade Price on a hand-over line is the
 * figure on the paper he signs and keeps, so he knows what he must bring back
 * in money or in goods; it moves nothing in the books.
 *
 * Reversible through the engine (SellerTransferCorrector): the goods go back
 * onto the batches they left, while they are still where the sheet put them.
 */
class SellerTransfer extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    public const KIND_HANDOVER = 'handover';

    public const KIND_RETURN = 'return';

    protected $fillable = [
        'business_id',
        'number',
        'kind',
        'employee_id',
        'seller_warehouse_id',
        'other_warehouse_id',
        'moved_on',
        'trade_value',
        'cost_value',
        'reason',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'moved_on' => 'date:Y-m-d',
            'trade_value' => 'decimal:4',
            'cost_value' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'kind', 'employee_id', 'moved_on', 'reversed_at', 'reversal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isHandover(): bool
    {
        return $this->kind === self::KIND_HANDOVER;
    }

    public function label(): string
    {
        return ($this->isHandover() ? 'Hand-over ' : 'Seller return ').$this->number;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SellerTransferLine::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function sellerWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'seller_warehouse_id');
    }

    public function otherWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'other_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
