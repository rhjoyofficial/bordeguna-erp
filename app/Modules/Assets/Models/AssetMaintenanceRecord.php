<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Assets\Enums\AssetCondition;
use App\Modules\Assets\Enums\MaintenanceKind;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fault reported, or work done to a thing (slice A4). MR-000001 per business.
 *
 * ⚠ Written only by RecordMaintenance. A done or cancelled record never
 * changes again, except for the money (cost and voucher), because the bill
 * often arrives after the work.
 */
class AssetMaintenanceRecord extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments;

    public const PHOTOS = 'repair';

    protected $fillable = [
        'business_id', 'number', 'asset_item_id', 'asset_maintenance_plan_id', 'kind', 'state', 'problem',
        'reported_by', 'reported_on', 'out_of_service', 'status_before', 'done_on', 'work_done', 'condition_after',
        'done_by_user_id', 'party_id', 'cost', 'expense_voucher_id', 'closed_by', 'closed_at', 'cancel_reason',
    ];

    protected $attributes = ['state' => 'open', 'out_of_service' => false];

    protected function casts(): array
    {
        return [
            'kind' => MaintenanceKind::class,
            'state' => MaintenanceState::class,
            'reported_on' => 'date:Y-m-d',
            'out_of_service' => 'boolean',
            'done_on' => 'date:Y-m-d',
            'condition_after' => AssetCondition::class,
            'cost' => 'decimal:4',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * ⚠ The register's readers, and the person who reported the fault (they
     * may add a photo of it from My assets and should see what they sent).
     */
    public function canAccessAttachments(User $user): bool
    {
        return $user->can('assets.items.view') || $user->id === $this->reported_by;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenancePlan::class, 'asset_maintenance_plan_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(AssetParty::class, 'party_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->state === MaintenanceState::Open;
    }

    public function whoLabel(): ?string
    {
        return $this->doneBy?->name ?? $this->party?->label();
    }
}
