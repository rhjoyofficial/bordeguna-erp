<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Enums\StockBatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockBatch extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'product_id',
        'warehouse_id',
        'batch_number',
        'expiry_date',
        'unit_cost',
        'initial_quantity',
        'current_quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockBatchStatus::class,
            'expiry_date' => 'date',
            'unit_cost' => 'decimal:4',
            'initial_quantity' => 'decimal:4',
            'current_quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /**
     * Oldest-first (FIFO): earliest expiry, falling back to earliest received,
     * for stock consumption (usage/adjustment-decrease/transfer-out).
     */
    public function scopeConsumable(Builder $query): Builder
    {
        return $query->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->orderByRaw('expiry_date IS NULL, expiry_date asc')
            ->orderBy('created_at');
    }
}
