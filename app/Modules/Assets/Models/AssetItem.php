<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
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
        // Slice A4: a service contract, and who holds it.
        'service_ends_on',
        'service_party_id',
        'note',
        'created_by',
        // Custody (slice A2): written only by MoveAssets and the acknowledgement.
        'custodian_employee_id',
        'custodian_party_id',
        'custody_since',
        'acknowledged_at',
        'transit_movement_id',
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
            'acquired_on' => 'date:Y-m-d',
            'warranty_ends_on' => 'date:Y-m-d',
            'service_ends_on' => 'date:Y-m-d',
            'purchase_price' => 'decimal:4',
            'custody_since' => 'date:Y-m-d',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ⚠ Price deliberately left out: the history of an item is read by people
        // who may not see what it cost.
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'brand', 'model', 'serial_number', 'office_id', 'warehouse_id', 'area', 'status', 'condition', 'parent_id', 'warranty_ends_on', 'service_ends_on', 'service_party_id', 'custodian_employee_id', 'custodian_party_id'])
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

    public function custodianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_employee_id');
    }

    public function custodianParty(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'custodian_party_id');
    }

    public function serviceParty(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'service_party_id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(AssetMaintenancePlan::class, 'asset_item_id');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class, 'asset_item_id');
    }

    public function transitMovement(): BelongsTo
    {
        return $this->belongsTo(AssetMovement::class, 'transit_movement_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(AssetHolding::class, 'asset_item_id');
    }

    public function movementLines(): HasMany
    {
        return $this->hasMany(AssetMovementLine::class, 'asset_item_id');
    }

    /**
     * Who has it, as words. A counted group answers from its holdings.
     */
    public function custodyLabel(): string
    {
        if ($this->transit_movement_id !== null) {
            return 'On its way';
        }

        if ($this->custodianEmployee) {
            return $this->custodianEmployee->fullName();
        }

        if ($this->custodianParty) {
            return $this->custodianParty->label();
        }

        return 'Nobody, in store';
    }

    public function isInTransit(): bool
    {
        return $this->transit_movement_id !== null;
    }

    /**
     * Tagged things with an outside party past the "expected back" date of
     * the send-out that put them there (13 Oct 2026). ⚠ The LATEST send-out
     * of the thing decides, read off the movement itself: there is nothing on
     * the item to clear when it comes back, custody alone says it is back.
     *
     * @param  Builder<AssetItem>  $query
     * @return Builder<AssetItem>
     */
    public function scopeOverdueFromOutside(Builder $query): Builder
    {
        return $query->whereNotNull('custodian_party_id')
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('asset_movements as m')
                ->join('asset_movement_lines as l', 'l.asset_movement_id', '=', 'm.id')
                ->whereColumn('l.asset_item_id', 'asset_items.id')
                ->where('m.kind', AssetMovementKind::SendOut->value)
                ->whereColumn('m.to_party_id', 'asset_items.custodian_party_id')
                ->where('m.expected_back_on', '<', now()->toDateString())
                ->whereRaw('m.id = (select max(m2.id) from asset_movements m2 join asset_movement_lines l2 on l2.asset_movement_id = m2.id where l2.asset_item_id = asset_items.id and m2.kind = ?)', [AssetMovementKind::SendOut->value]));
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
