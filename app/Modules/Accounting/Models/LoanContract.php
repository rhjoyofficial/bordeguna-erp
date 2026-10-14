<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDayCount;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⚠⚠ A LOAN AGREEMENT IN THE LOAN REGISTER (loans plan v2, phase 2, 14 Oct
 * 2026): money the business lent to, or borrowed from, a counterparty outside
 * the group, on written terms.
 *
 * The register is the CONTRACTUAL sub-ledger: terms, schedule, what was paid
 * against which installment. The general ledger stays the only accounting
 * truth, and PostJournal its only writer. Nothing here posts by itself: every
 * journal comes from a person pressing a button (disburse, repay, accrue,
 * approve a write-off), and every line it writes on a loan head carries this
 * loan's id, so the two can be totalled against each other loan by loan
 * (Support\Loans\LoanReconciliation).
 *
 * ⚠ What is still owed is never stored here. It is the sum of the live
 * entries (LoanEntry), the same rule HRM's employee loans keep.
 *
 * ⚠ Not HRM's Loan: employee loans and salary advances stay on 1170 with HRM.
 * The workflow key is therefore `loan_contract`, never the default `loan`.
 */
class LoanContract extends Model implements MarksSelfApproval
{
    use Approvable, BelongsToBusiness, HasAttachments, LogsActivity;

    /** The signed agreement, the sanction letter. */
    public const AGREEMENT_COLLECTION = 'agreement';

    protected $fillable = [
        'business_id', 'loan_number', 'direction', 'counterparty_id', 'status', 'currency',
        'principal_minor', 'start_date', 'maturity_date', 'annual_rate', 'interest_free_reason',
        'day_count', 'repayment_type', 'frequency', 'grace_months', 'waterfall', 'collateral', 'purpose',
        'principal_account_id', 'current_portion_account_id', 'interest_accrual_account_id', 'interest_pl_account_id',
        'tds_certificate_ref', 'tds_certificate_rate', 'terms_version', 'cancel_reason', 'approved_at', 'settled_on',
        'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'BDT',
        'annual_rate' => '0.0000',
        'day_count' => 'actual_365',
        'grace_months' => 0,
        'waterfall' => 'interest_first',
        'terms_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'direction' => LoanDirection::class,
            'status' => LoanStatus::class,
            'day_count' => LoanDayCount::class,
            'repayment_type' => LoanRepaymentType::class,
            'frequency' => LoanFrequency::class,
            'waterfall' => LoanWaterfall::class,
            'principal_minor' => 'integer',
            'grace_months' => 'integer',
            'terms_version' => 'integer',
            'annual_rate' => 'decimal:4',
            'tds_certificate_rate' => 'decimal:4',
            'start_date' => 'date:Y-m-d',
            'maturity_date' => 'date:Y-m-d',
            'settled_on' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'loan_number', 'direction', 'counterparty_id', 'status', 'principal_minor', 'start_date', 'maturity_date',
                'annual_rate', 'interest_free_reason', 'day_count', 'repayment_type', 'frequency', 'grace_months',
                'waterfall', 'principal_account_id', 'tds_certificate_ref', 'tds_certificate_rate', 'terms_version',
                'cancel_reason', 'settled_on',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvalWorkflowKey(): string
    {
        return 'loan_contract';
    }

    /**
     * Rule #22: the Owner approving a loan they drafted themselves is allowed,
     * with a reason, and the history marks it.
     */
    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function principalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'principal_account_id');
    }

    public function currentPortionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_portion_account_id');
    }

    public function interestAccrualAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_accrual_account_id');
    }

    public function interestPlAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_pl_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduleRows(): HasMany
    {
        return $this->hasMany(LoanScheduleRow::class)->orderBy('version')->orderBy('sequence');
    }

    public function termsVersions(): HasMany
    {
        return $this->hasMany(LoanTermsVersion::class)->orderBy('version');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LoanEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(LoanAmendment::class)->orderByDesc('id');
    }

    public function writeOffs(): HasMany
    {
        return $this->hasMany(LoanWriteOff::class)->orderByDesc('id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', LoanStatus::liveValues());
    }

    public function scopeApprovedOnce(Builder $query): Builder
    {
        return $query->whereIn('status', LoanStatus::approvedValues());
    }

    public function principal(): Money
    {
        return Money::ofMinor((int) $this->principal_minor);
    }

    /** The annual rate in ten-thousandths of a percent: 12.5% is 125000. */
    public function rateE4(): int
    {
        return LoanMath::rateE4((string) $this->annual_rate);
    }

    /**
     * The ledger heads this loan's lines sit on: principal, its current-portion
     * pair when the principal head is long-term, and the accrued-interest head.
     *
     * @return list<int>
     */
    public function headIds(): array
    {
        return array_values(array_filter([
            (int) $this->principal_account_id,
            $this->current_portion_account_id === null ? null : (int) $this->current_portion_account_id,
            (int) $this->interest_accrual_account_id,
        ]));
    }

    /** @return list<int> the heads that hold principal (the principal head and its current portion). */
    public function principalHeadIds(): array
    {
        return array_values(array_filter([
            (int) $this->principal_account_id,
            $this->current_portion_account_id === null ? null : (int) $this->current_portion_account_id,
        ]));
    }

    public function isInterestBearing(): bool
    {
        return $this->rateE4() > 0;
    }

    /** "LN-2026-0001 Tycoon - Hi-Tech Park" - how a picker or a memo names it. */
    public function label(): string
    {
        return trim("{$this->loan_number} {$this->counterparty?->legal_name}");
    }
}
