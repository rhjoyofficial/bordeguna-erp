<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\RefusesSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A supplier bill, payment, advance or refund waiting for a second person
 * (ruling P7, Joy's decision 18 Oct 2026).
 *
 * ⚠⚠ NOTHING IS POSTED WHILE IT WAITS. It holds what was typed (`payload`),
 * not a bill or a payment: no Accounts Payable, no cash book, no GRNI, no stock
 * cost moved, no number taken. On approval the SAME domain action that refused
 * to post it is run again with the payload, re-checking every rule as of that
 * moment (period open, bill still uninvoiced, money available), and the
 * document it creates is linked here as `document`. A rejection keeps the row
 * with its reason.
 *
 * ⚠ Never approved by its maker, whatever their role, and only by the Finance
 * policy's approver roles (Owner or Manager by default).
 */
class PurchaseApproval extends Model implements RefusesSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity, WithdrawsBySendingBack;

    public const KINDS = ['bill', 'payment', 'advance', 'refund', 'supplier_refund'];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** B13 (24 Oct 2026): pulled back by its sender before anyone decided. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'business_id',
        'kind',
        'supplier_id',
        'amount_minor',
        'document_date',
        'reference',
        'summary',
        'reasons',
        'payload',
        'status',
        'document_type',
        'document_id',
        'decision_note',
        'decided_by',
        'decided_at',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'document_date' => 'date:Y-m-d',
            'reasons' => 'array',
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'supplier_id', 'amount_minor', 'status', 'decision_note', 'document_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('purchase_approval');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The bill, payment, advance or refund the approval created. */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function money(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function number(): string
    {
        return 'PA-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'bill' => 'Supplier bill',
            'payment' => 'Supplier payment',
            'advance' => 'Supplier advance',
            'refund' => 'Advance refund',
            'supplier_refund' => 'Supplier refund',
            default => ucfirst((string) $this->kind),
        };
    }

    /** @return list<string> */
    public function reasonLabels(): array
    {
        return array_values(array_map(fn ($reason) => (string) ($reason['label'] ?? $reason['code'] ?? ''), (array) $this->reasons));
    }

    /**
     * ⚠⚠ The maker never approves (Joy, 18 Oct 2026), and nor does anybody
     * outside the Finance policy's approver roles. Checked by ApprovalRequest
     * BEFORE the workflow stage, so it holds even where a stage would admit.
     */
    public function refusesApprovalBy(User $user, ApprovalRequest $request): bool
    {
        if ($user->id === (int) $this->created_by || $user->id === (int) $request->requested_by) {
            return true;
        }

        if ($user->is_super_admin) {
            return false;
        }

        foreach (FinancePolicy::for((int) $this->business_id)->purchasingApproverRoles() as $role) {
            if ($user->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of these kinds are waiting, for the notice on their list.
     *
     * @param  list<string>  $kinds
     */
    public static function pendingCount(array $kinds): int
    {
        return self::query()->whereIn('kind', $kinds)->where('status', self::PENDING)->count();
    }

    /**
     * The approval a posted document went through, for its page, or null.
     *
     * @return array{id: int, number: string, decided_by: ?string, decided_at: ?string, reasons: list<string>}|null
     */
    public static function postedVia(Model $document): ?array
    {
        $approval = self::query()->with('decidedBy:id,name')
            ->where('document_type', $document->getMorphClass())->where('document_id', $document->getKey())->first();

        return $approval === null ? null : [
            'id' => $approval->id,
            'number' => $approval->number(),
            'decided_by' => $approval->decidedBy?->name,
            'decided_at' => $approval->decided_at?->toDateTimeString(),
            'reasons' => $approval->reasonLabels(),
        ];
    }

    public function approvalWorkflowKey(): string
    {
        return 'purchase_approval';
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return $this->isPending() ? null : "{$this->number()} is no longer waiting.";
    }

    /** Closed as withdrawn: nothing was posted while it waited, so nothing is undone. */
    protected function withdrawn(User $by, ActionReason $reason): void
    {
        $this->forceFill(['status' => self::WITHDRAWN, 'decision_note' => $this->withdrawnNote($reason), 'decided_by' => $by->id, 'decided_at' => now()])->save();
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return $this->number().' ('.$this->kindLabel().', '.$this->money()->format().')';
    }
}
