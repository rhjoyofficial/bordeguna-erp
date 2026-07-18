<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The generic approval-queue record shared by every "requires approval"
 * document type across modules (stock adjustments/transfers today; Purchasing
 * and HR approvals attach here too rather than each rolling their own status/
 * requested_by/reviewed_by columns). The concrete domain fields (product,
 * quantity, ...) live on the approvable model itself — this table only tracks
 * who asked, who decided, and when.
 */
class ApprovalRequest extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'review_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function approve(User $reviewedBy): void
    {
        $this->update([
            'status' => ApprovalStatus::Approved,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
        ]);
    }

    public function reject(User $reviewedBy, ?string $reviewNote = null): void
    {
        $this->update([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);
    }
}
