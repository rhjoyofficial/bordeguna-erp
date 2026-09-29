<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What the ASM saw when he visited a distributor (Joy, answer 17): a date,
 * notes and photos. Optional, and nothing waits for one.
 *
 * ⚠⚠ EVIDENCE, SO APPEND-ONLY. An inspection is what somebody saw on a day; a
 * later visit that sees something else is another inspection. The model refuses
 * an edit and a delete, so every path meets the rule, not only the route.
 *
 * ⚠⚠ VISIBLE ONLY THROUGH ITS LEAD. The global scope below runs the lead's own
 * visibility scope, so an inspection (and, through ControlsAttachmentAccess and
 * FileVisibility::ROW_SCOPED, its photos) is found exactly where its lead is
 * found. Without it the inspection's own id would be a way round the org chart.
 */
class DistributorInspection extends Model implements ControlsAttachmentAccess
{
    use BelongsToBusiness, HasAttachments;

    public const PHOTOS = 'photos';

    protected $fillable = ['business_id', 'lead_id', 'inspected_on', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['inspected_on' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible_lead', fn (Builder $query) => $query->whereHas('lead'));

        static::updating(function (): never {
            throw new RuntimeException('An inspection records what was seen on a day and cannot be changed. Record another inspection.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('An inspection is evidence and cannot be deleted.');
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canAccessAttachments(User $user): bool
    {
        $lead = Lead::withoutGlobalScopes()->find($this->lead_id);

        return $lead !== null && $lead->canAccessAttachments($user);
    }
}
