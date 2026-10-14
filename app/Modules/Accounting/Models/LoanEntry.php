<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One movement the Loan Register knows about, and the journal that booked it.
 *
 * Each amount is a SIGNED change, in paisa, seen from the loan:
 *
 *   principal_minor          what is owed in principal (+ lent or borrowed,
 *                            - repaid or written off)
 *   accrued_interest_minor   interest accrued and not yet settled (+ accrued,
 *                            - settled or waived)
 *   interest_pl_minor        interest recognised in profit and loss (income on
 *                            a loan given, expense on one taken; - a waiver)
 *   interest_settled_minor   the GROSS interest a repayment settled
 *   tds_minor                tax withheld on that interest
 *   cash_minor               money that actually moved
 *
 * ⚠⚠ LIVE MEANS NOT REVERSED, and it is decided from the LEDGER, not only from
 * a flag: an entry whose journal has been reversed, by whatever path, stops
 * counting (scopeLive). The register cannot keep a repayment the ledger has
 * undone.
 */
class LoanEntry extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'loan_contract_id', 'kind', 'entry_date', 'journal_id', 'money_account_id',
        'principal_minor', 'accrued_interest_minor', 'interest_pl_minor', 'interest_settled_minor',
        'tds_minor', 'tds_rate', 'cash_minor', 'accrual_period', 'accrual_version', 'accrual_key',
        'submission_key', 'loan_write_off_id', 'note', 'created_by',
        'reversal_journal_id', 'reversal_reason', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LoanEntryKind::class,
            'entry_date' => 'date:Y-m-d',
            'principal_minor' => 'integer',
            'accrued_interest_minor' => 'integer',
            'interest_pl_minor' => 'integer',
            'interest_settled_minor' => 'integer',
            'tds_minor' => 'integer',
            'tds_rate' => 'decimal:4',
            'cash_minor' => 'integer',
            'accrual_version' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['kind', 'entry_date', 'journal_id', 'principal_minor', 'accrued_interest_minor', 'interest_settled_minor', 'tds_minor', 'cash_minor', 'reversal_reason', 'reversed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanContract::class, 'loan_contract_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(LoanWriteOff::class, 'loan_write_off_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanAllocation::class);
    }

    /**
     * Not reversed on the loan page, and its journal not reversed anywhere.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('loan_entries.reversed_at')
            ->where(fn (Builder $q) => $q->whereNull('loan_entries.journal_id')
                ->orWhereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('journals as reversals')
                    ->whereColumn('reversals.reverses_journal_id', 'loan_entries.journal_id')));
    }

    public function isLive(): bool
    {
        return $this->reversed_at === null
            && ($this->journal_id === null || ! Journal::withoutGlobalScopes()->where('reverses_journal_id', $this->journal_id)->exists());
    }

    public function cash(): Money
    {
        return Money::ofMinor((int) $this->cash_minor);
    }
}
