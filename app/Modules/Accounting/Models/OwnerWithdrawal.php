<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Stock the proprietor took for personal use (Phase 2, 17 Oct 2026, ruling
 * Q2 "Proprietor Moin": a drawing, never a sale).
 *
 * NOT a sale: no customer, no invoice, no revenue, no receivable. The goods
 * leave at the reserved batch's carrying cost, Dr Owner's Drawings / Cr Stock
 * on Hand (PostOwnerWithdrawal). Money the owner pays in later is a receipt
 * voucher crediting the same drawings head, never revenue.
 *
 * Authorised each time (D2: no monthly cap, every withdrawal authorised and
 * audited): by a second person holding owner_withdrawal.authorise, or by the
 * proprietor himself with a note (self_authorised). Approval reserves the
 * FIFO batch of each line and freezes its cost (ruling Q1); posting issues
 * exactly that batch, or sends the document back for re-approval when the
 * batch is gone or re-costed.
 */
class OwnerWithdrawal extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'number',
        'proprietor_user_id',
        'employee_id',
        'warehouse_id',
        'status',
        'withdrawn_on',
        'note',
        'authorised_by',
        'authorised_at',
        'authorisation_note',
        'self_authorised',
        'reapproval_reason',
        'cost_total_paisa',
        'reference_total_paisa',
        'journal_id',
        'posted_at',
        'posted_by',
        'created_by',
        'reversed_on',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'reversal_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OwnerWithdrawalStatus::class,
            'withdrawn_on' => 'date:Y-m-d',
            'authorised_at' => 'datetime',
            'self_authorised' => 'boolean',
            'cost_total_paisa' => 'integer',
            'reference_total_paisa' => 'integer',
            'posted_at' => 'datetime',
            'reversed_on' => 'date:Y-m-d',
            'reversed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'withdrawn_on', 'warehouse_id', 'proprietor_user_id', 'authorised_by', 'self_authorised', 'cost_total_paisa', 'reapproval_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OwnerWithdrawalLine::class)->orderBy('sort')->orderBy('id');
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reference');
    }

    public function proprietor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietor_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === OwnerWithdrawalStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === OwnerWithdrawalStatus::Approved;
    }

    public function isPosted(): bool
    {
        return $this->status === OwnerWithdrawalStatus::Posted;
    }

    /** ⭐ B24 (25 Oct 2026): posted, then taken back (ReverseOwnerWithdrawal). */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function costTotal(): Money
    {
        return Money::ofMinor((int) $this->cost_total_paisa);
    }

    public function referenceTotal(): ?Money
    {
        return $this->reference_total_paisa === null ? null : Money::ofMinor((int) $this->reference_total_paisa);
    }

    /** Reference less cost: what the goods would have fetched at the reference price, over what they cost. */
    public function differenceTotal(): ?Money
    {
        $reference = $this->referenceTotal();

        return $reference === null ? null : $reference->plus($this->costTotal()->negated());
    }
}
