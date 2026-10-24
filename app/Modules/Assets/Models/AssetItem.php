<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetPhotoKind;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
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
 *
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a thing nobody ever used goes to the
 * Recycle bin (Delete, `whyNotRemovable`), its tag still reserved (the unique
 * index keeps the trashed row, and numbering reads trashed rows). A thing
 * that left the company is Disposed (DisposeAssetItem), never deleted.
 *
 * ⚠⚠ `withoutGlobalScopes()` ALSO DROPS THE SOFT-DELETE SCOPE. Every unscoped
 * query of this model that must not see a binned thing says
 * `whereNull('deleted_at')` itself (CountAssets, MoveAssets, My assets).
 */
class AssetItem extends Model implements ControlsAttachmentAccess, Removable
{
    use BelongsToBusiness, HasAttachments, LogsActivity, Recyclable;

    /** How a thing leaves the company. "Registered by mistake" is NOT one: that is Delete (FINAL-PLAN 8.1, 20). */
    public const DISPOSAL_METHODS = ['sold', 'scrapped', 'donated', 'lost'];

    /** Undo disposal, within this many days (FINAL-PLAN 8.1, 10). */
    public const UNDO_DISPOSAL_DAYS = 30;

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
        // Corrections wave 3 (24 Oct 2026).
        'import_batch_id',
        'disposed_on',
        'disposed_at',
        'disposed_by',
        'disposal_method',
        'disposal_reason',
        'disposal_snapshot',
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
            'disposed_on' => 'date:Y-m-d',
            'disposed_at' => 'datetime',
            'disposal_snapshot' => 'array',
        ];
    }

    /** What the Recycle bin calls it. */
    public function binLabel(): string
    {
        return "Thing {$this->tag} {$this->name}";
    }

    /**
     * Never used = Delete (FINAL-PLAN 6.4). "Used" is anything that points at
     * it: a hand-over that still stands, a count, a repair, a part, a login, a
     * photo other than its identity photo, a disposal, a fixed asset.
     *
     * ⚠ A hand-over and its reversal cancel out: a thing whose every movement
     * was reversed (a wrong import with a holder, a hand-over to the wrong
     * person) never really moved.
     */
    public function whyNotRemovable(): ?string
    {
        if ($this->status === AssetStatus::Disposed) {
            return "{$this->tag} was disposed, so it stays in the register. Use Undo disposal if that was a mistake.";
        }

        if ($this->linkedFixedAssetId() !== null) {
            return "{$this->tag} is linked to a fixed asset on the books. Put it right on the fixed asset page first.";
        }

        if ($this->hasStandingMovements()) {
            return "{$this->tag} has been handed over, so it cannot be deleted. Reverse the hand-over first, or Dispose it if it left the company.";
        }

        return $this->usedOtherThanByMovements();
    }

    /**
     * Every "used" test except custody and disposal: what Reverse import asks
     * once it has set the sheet's own hand-overs aside.
     */
    public function usedOtherThanByMovements(): ?string
    {
        if ($this->status === AssetStatus::Disposed) {
            return "{$this->tag} was disposed, so it stays in the register.";
        }

        if (AssetCountLine::query()->where('asset_item_id', $this->id)->exists()) {
            return "{$this->tag} has been counted at a stock-take, so it cannot be deleted. Dispose it if it left the company.";
        }

        if (AssetMaintenanceRecord::query()->withoutGlobalScopes()->where('asset_item_id', $this->id)->exists()
            || AssetMaintenancePlan::query()->withoutGlobalScopes()->where('asset_item_id', $this->id)->exists()) {
            return "{$this->tag} has maintenance on record, so it cannot be deleted. Dispose it if it left the company.";
        }

        if (self::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('parent_id', $this->id)->exists()) {
            return "{$this->tag} has parts registered under it. Delete or move the parts first.";
        }

        if (AssetCredential::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('credentialable_type', $this->getMorphClass())->where('credentialable_id', $this->id)->exists()) {
            return "{$this->tag} carries logins. Delete its logins first.";
        }

        if ($this->attachments()->whereNotIn('collection', [AssetPhotoKind::Identity->value, AssetPhotoKind::Serial->value])->exists()) {
            return "{$this->tag} has photos of its condition or repairs, so it cannot be deleted. Dispose it if it left the company.";
        }

        return null;
    }

    /** A thing is never switched off: it is deleted when unused, else disposed. */
    public function whyNotDeactivatable(): ?string
    {
        return 'A thing is not switched off. Delete it if it was never used, or Dispose it if it left the company.';
    }

    /** Restore re-checks what may have changed while it sat in the bin (FINAL-PLAN 6.3). */
    public function whyNotRestorable(): ?string
    {
        if ($this->parent_id !== null && ! self::query()->withoutGlobalScopes()->whereKey($this->parent_id)->whereNull('deleted_at')->exists()) {
            return "{$this->tag} is a part of a thing that is in the Recycle bin. Restore that thing first.";
        }

        if (! AssetCategory::query()->withoutGlobalScopes()->whereKey($this->asset_category_id)->exists()) {
            return "The category of {$this->tag} no longer exists.";
        }

        return null;
    }

    /**
     * A movement line of a hand-over that still stands: neither reversed nor
     * itself a reversal. A cancelled transfer counts as reversed.
     */
    public function hasStandingMovements(): bool
    {
        return AssetMovementLine::query()
            ->where('asset_item_id', $this->id)
            ->whereHas('movement', fn ($q) => $q->withoutGlobalScopes()
                ->whereNull('reverses_movement_id')
                ->whereNull('reversed_at'))
            ->exists();
    }

    /**
     * ⚠ The financial register has no link to a thing today (FixedAsset has no
     * thing column, 24 Oct 2026). The moment one is added, Delete and Dispose
     * refuse here and send the person to the fixed asset page, which belongs
     * to the accounts corrections (wave 2).
     */
    public function linkedFixedAssetId(): ?int
    {
        static $hasColumn = null;
        $hasColumn ??= Schema::hasColumn('asset_items', 'fixed_asset_id');

        return $hasColumn && $this->getAttribute('fixed_asset_id') !== null ? (int) $this->getAttribute('fixed_asset_id') : null;
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(AssetImportBatch::class, 'import_batch_id');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function disposalMethodLabel(): ?string
    {
        return match ($this->disposal_method) {
            'sold' => 'Sold',
            'scrapped' => 'Scrapped',
            'donated' => 'Donated',
            'lost' => 'Lost or stolen',
            default => null,
        };
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
