<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Samples made from raw materials (Joy, 25 Oct 2026: "sometime we make
 * sample product from raw materials"). A small production: the raw material
 * batches picked are consumed at their cost and one SAMPLE LOT of the output
 * product is created carrying exactly that cost (RecordSampleMake). The lot is
 * an ordinary batch, numbered SMPL-..., that Give sample then takes from.
 */
class SampleMake extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'number', 'made_on', 'product_id', 'warehouse_id', 'quantity',
        'stock_batch_id', 'stock_movement_id', 'cost_total_paisa', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['made_on' => 'date:Y-m-d', 'quantity' => 'decimal:4', 'cost_total_paisa' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['made_on', 'product_id', 'quantity', 'cost_total_paisa'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(SampleMakeInput::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function costMoney(): Money
    {
        return Money::ofMinor((int) $this->cost_total_paisa);
    }
}
