<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Models\Office;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One physical thing the business keeps, or one counted group of identical
 * small things (asset management plan, slice A1, 16 September 2026).
 *
 * ⭐⭐ PHYSICAL, NOT FINANCIAL. Registering an item never creates a fixed asset
 * and never posts a journal; linking one to the financial register is a
 * deliberate Owner or Manager step (slice A5). A mouse is tracked here and was
 * expensed; a laptop is tracked here AND may be a fixed asset.
 *
 * ⚠ `business_id` is the OWNER. Where it is and who has it are separate facts
 * (location here; custody from slice A2), never inferred from one another.
 *
 * ⚠ The tag is permanent: it is printed on a sticker and encoded in its QR code.
 */
class AssetItem extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    protected $fillable = [
        'business_id',
        'asset_category_id',
        'tag',
        'sequence',
        'name',
        'tracking',
        'quantity',
        'brand',
        'model',
        'serial_number',
        'specification',
        'office_id',
        'warehouse_id',
        'area',
        'status',
        'condition',
        'parent_id',
        'acquired_on',
        'supplier_id',
        'purchase_price',
        'warranty_ends_on',
        'note',
        'created_by',
    ];

    protected $attributes = [
        'tracking' => 'individual',
        'quantity' => 1,
        'status' => 'in_store',
        'condition' => 'good',
    ];

    protected function casts(): array
    {
        return [
            'tracking' => AssetTracking::class,
            'status' => AssetStatus::class,
            'condition' => AssetCondition::class,
            'quantity' => 'integer',
            'sequence' => 'integer',
            'acquired_on' => 'date',
            'warranty_ends_on' => 'date',
            'purchase_price' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ⚠ Price deliberately left out: the history of an item is read by people
        // who may not see what it cost.
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'brand', 'model', 'serial_number', 'office_id', 'warehouse_id', 'area', 'status', 'condition', 'parent_id', 'warranty_ends_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * ⚠ Photos of an item are for the people who keep the register.
     */
    public function canAccessAttachments(User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIndividual(): bool
    {
        return $this->tracking === AssetTracking::Individual;
    }

    public function locationLabel(): string
    {
        $place = $this->office?->name ?? $this->warehouse?->name ?? 'No location';

        return $this->area ? "{$place} - {$this->area}" : $place;
    }

    /**
     * The permanent address a QR code carries.
     */
    public function tagUrl(): string
    {
        return url('/assets/items/t/'.rawurlencode($this->tag));
    }
}
