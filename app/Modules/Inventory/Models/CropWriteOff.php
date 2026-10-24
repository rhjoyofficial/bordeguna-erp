<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a crop that failed (flood, pests), its
 * cost written off a Crops in Progress head as a loss.
 *
 * ```
 * Dr  the loss head the person chose (5210 Crop Failure Loss)
 *     Cr  Crops in Progress
 * ```
 *
 * Raised with a reason, it WAITS for the Owner (the `crop_write_off`
 * workflow) and touches no ledger until approved (ApproveCropWriteOff). A
 * rejected one stays, rejected. An approved one is reversed through the
 * corrections engine (CropWriteOffCorrector).
 */
class CropWriteOff extends Model implements MarksSelfApproval
{
    use Approvable, BelongsToBusiness, Reversible;

    public const WAITING = 'waiting';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'business_id',
        'number',
        'written_off_on',
        'crop_account_id',
        'loss_account_id',
        'amount_paisa',
        'reason_text',
        'status',
        'journal_id',
        'idempotency_key',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'written_off_on' => 'date:Y-m-d',
            'amount_paisa' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Self-approval stays allowed (Joy, 10 Sep 2026): the Owner who raised it
     * approves it with a short note, and the history marks it (Rule #22).
     */
    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function selfApprovalPrompt(): string
    {
        return 'You raised this write-off yourself. Approving it is allowed, but write a short note first; it is kept in the approval history.';
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function cropAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'crop_account_id');
    }

    public function lossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loss_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
