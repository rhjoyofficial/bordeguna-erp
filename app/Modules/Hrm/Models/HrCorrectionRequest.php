<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\RefusesSelfApproval;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\Reason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): an HR cancellation or
 * undo that waits for a second person.
 *
 *   cancel_leave      an approved leave whose days sit in a cycle whose payroll
 *                     is already approved ("HR plus one other approver")
 *   cancel_movement   the same for a movement
 *   undo_separation   always ("HR requests, a second approver confirms")
 *
 * ⚠⚠ It stores the INTENT and the record's fingerprint, never a result.
 * Nothing changes while it waits. On approval the action locks and re-reads
 * the record and asks its policy again; a record that changed meanwhile fails
 * the request ("look again") instead of acting on stale data.
 *
 * ⚠ Only the person who asked is refused as the approver (Joy, 19 Oct 2026),
 * checked before the stage so it holds for a super admin too.
 */
class HrCorrectionRequest extends Model implements RefusesSelfApproval
{
    use Approvable, BelongsToBusiness, LogsActivity;

    public const CANCEL_LEAVE = 'cancel_leave';

    public const CANCEL_MOVEMENT = 'cancel_movement';

    public const UNDO_SEPARATION = 'undo_separation';

    protected $fillable = [
        'business_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'reason_id',
        'reason_text',
        'why',
        'fingerprint',
        'status',
        'pending_key',
        'failure_message',
        'requested_by',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('corrections')
            ->logOnly(['status', 'failure_message'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** A bare instance answers the key too (EveryApprovalReachesSomebodyTest). */
    public function approvalWorkflowKey(): string
    {
        return 'hr_correction_request';
    }

    public function refusesApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->requested_by || $user->id === (int) $request->requested_by;
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function reasonDisplay(): string
    {
        $name = $this->reason?->name;

        return $name === null ? (string) $this->reason_text : "{$name}: {$this->reason_text}";
    }

    public function verb(): string
    {
        return match ($this->action) {
            self::CANCEL_LEAVE => 'Cancel leave',
            self::CANCEL_MOVEMENT => 'Cancel movement',
            self::UNDO_SEPARATION => 'Undo separation',
            default => 'HR change',
        };
    }

    /** "Cancel leave: Leave #12, Karim Ahmed, 3 days (06/10/2026 to 08/10/2026)" for the inbox card. */
    public function summaryLine(): string
    {
        return "{$this->verb()}: {$this->subject_label}";
    }

    /** One request per record while it waits. */
    public static function pendingKeyFor(string $action, Model $subject): string
    {
        return $action.':'.$subject->getMorphClass().':'.$subject->getKey();
    }

    public static function pendingFor(string $action, Model $subject): ?self
    {
        return self::query()->where('pending_key', self::pendingKeyFor($action, $subject))->first();
    }
}
