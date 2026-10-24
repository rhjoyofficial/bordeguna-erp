<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named shelf location inside one warehouse (C-BIN-2/3). Identity is the
 * `code`, unique per warehouse; retirement is `is_active = false` once any
 * batch has pointed at it.
 */
class WarehouseBin extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, LogsActivity, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'warehouse_id',
        'code',
        'name',
        'is_active',
    ];

    /**
     * ⚠ The database default, repeated on the model - the Warehouse lesson: a
     * freshly created instance has NULL `is_active` until the row is written,
     * and any caller checking `! $bin->is_active` would refuse a bin the
     * database considers active.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Identity and the switch, nothing chatty - the same field discipline as
     * Warehouse, whose shelf structure this table extends.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'is_active', 'warehouse_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'bin_id');
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['warehouse_bin_id', 'bin_id'];
    }

    public function binLabel(): string
    {
        return 'Bin '.$this->code;
    }
}
