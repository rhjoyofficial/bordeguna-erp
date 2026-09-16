<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One hand-over (slice A2): things going from a place or a person to a place
 * or a person, on a day, with what was noted about them.
 *
 * ⚠⚠ NEVER EDITED. Written once by MoveAssets; a mistake is another movement.
 * The hand-over photos live here (collection `handover`).
 */
class AssetMovement extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments;

    public const PHOTOS = 'handover';

    protected $fillable = [
        'business_id', 'number', 'kind', 'status',
        'from_office_id', 'from_warehouse_id', 'from_employee_id', 'from_party_id',
        'to_office_id', 'to_warehouse_id', 'to_employee_id', 'to_party_id',
        'moved_on', 'note', 'created_by', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AssetMovementKind::class,
            'status' => AssetMovementStatus::class,
            'moved_on' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function canAccessAttachments(User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetMovementLine::class);
    }

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function fromParty(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'from_party_id');
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function toParty(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'to_party_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** The from side as words. */
    public function fromLabel(): string
    {
        return $this->fromEmployee?->fullName()
            ?? $this->fromParty?->label()
            ?? $this->fromOffice?->name
            ?? $this->fromWarehouse?->name
            ?? '-';
    }

    /** The to side as words. */
    public function toLabel(): string
    {
        return $this->toEmployee?->fullName()
            ?? $this->toParty?->label()
            ?? $this->toOffice?->name
            ?? $this->toWarehouse?->name
            ?? '-';
    }

    public function isDispatched(): bool
    {
        return $this->status === AssetMovementStatus::Dispatched;
    }
}
