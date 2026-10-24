<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A commission deal made with ONE person (Joy's Q18, 25 Oct 2026): nobody
 * earns commission unless an agreement names them. See the migration
 * 2026_10_25_320000 for the whole shape.
 *
 * The person is an employee, a customer (a shop) or a supplier (an outside
 * agent is kept as a supplier, so they have the one ledger party they already
 * would have). Their commission ledger is "Commission Payable > their name".
 *
 * ⚠ A target deal whose top reward is above the Finance policy's second
 * approval limit waits for a second person (the voucher workflow, as a
 * set-off does); self-approval stays allowed with a reason (Joy, 10 Sep).
 * A Tk-per-unit or percent deal has no amount of its own: its money is
 * checked when it is settled.
 */
class CommissionAgreement extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const PARTY_TYPES = ['employee', 'customer', 'supplier'];

    public const DEAL_TYPES = ['per_unit', 'percent', 'target'];

    protected $fillable = [
        'business_id', 'number', 'agreed_on', 'party_type', 'employee_id', 'customer_id', 'supplier_id',
        'valid_from', 'valid_to', 'deal_type', 'rate', 'target_basis', 'counts_credited_sales',
        'counts_own_purchases', 'note', 'status', 'decision_note', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::ACTIVE];

    protected function casts(): array
    {
        return [
            'agreed_on' => 'date:Y-m-d',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'rate' => 'decimal:4',
            'counts_credited_sales' => 'boolean',
            'counts_own_purchases' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'party_type', 'valid_from', 'valid_to', 'deal_type', 'rate', 'target_basis', 'status', 'note'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('commission_agreement');
    }

    /** ⚠ The voucher workflow, on purpose: the same second person who approves a large payment. */
    public function approvalWorkflowKey(): string
    {
        return 'voucher';
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionAgreementItem::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionAgreementTier::class)->orderBy('threshold');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CommissionSettlement::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The person as a ledger party (Employee, Customer or Supplier). */
    public function party(): ?Model
    {
        return match ($this->party_type) {
            'employee' => $this->employee,
            'customer' => $this->customer,
            'supplier' => $this->supplier,
            default => null,
        };
    }

    public function partyId(): ?int
    {
        $id = match ($this->party_type) {
            'employee' => $this->employee_id,
            'customer' => $this->customer_id,
            'supplier' => $this->supplier_id,
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    public function partyName(): string
    {
        $party = $this->party();

        if ($party instanceof Employee) {
            return $party->fullName();
        }

        return (string) ($party?->getAttribute('name') ?? '');
    }

    /** "Tk 5.00 per unit", "2% of sales", "Target: 100 units = Tk 2,000". */
    public function dealSummary(): string
    {
        return match ($this->deal_type) {
            'per_unit' => 'Tk '.number_format((float) $this->rate, 2).' per unit sold',
            'percent' => rtrim(rtrim(number_format((float) $this->rate, 2), '0'), '.').'% of the sale value',
            default => 'Target: '.$this->tiers->map(fn (CommissionAgreementTier $tier) => ($this->target_basis === 'units'
                ? rtrim(rtrim(number_format((float) $tier->threshold, 2), '0'), '.').' units'
                : 'Tk '.number_format((float) $tier->threshold, 2)).' = Tk '.number_format((float) $tier->reward, 2))->implode('; '),
        };
    }

    /** The largest single amount this deal can promise (the top reward), or null when it has none of its own. */
    public function promisedMaximum(): ?string
    {
        if ($this->deal_type !== 'target') {
            return null;
        }

        return (string) ($this->tiers->max(fn (CommissionAgreementTier $tier) => (float) $tier->reward) ?? 0);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The last day sales count: its end, or today when it has none. */
    public function lastCountingDay(?Carbon $upTo = null): Carbon
    {
        $upTo ??= Carbon::today();

        return $this->valid_to !== null && $this->valid_to->lt($upTo) ? $this->valid_to->copy() : $upTo->copy();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Waiting for approval',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
            default => $this->valid_to !== null && $this->valid_to->lt(Carbon::today()) ? 'Ended' : 'Active',
        };
    }

    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function selfApprovalPrompt(): string
    {
        return 'You made this agreement yourself. Approving it is allowed, but write a short reason in the note first; it is kept in the approval history.';
    }

    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string
    {
        return $this->isPending() ? null : "{$this->number} is not waiting for approval.";
    }

    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void
    {
        $request->sendBack($by, "Withdrawn by {$by->name}: {$reason->display()}");
        $this->update(['status' => self::WITHDRAWN, 'decision_note' => "Withdrawn: {$reason->display()}"]);
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return "Commission agreement {$this->number} ({$this->partyName()})";
    }
}
