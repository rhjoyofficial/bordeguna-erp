<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A standing cost: what we have committed to pay, to whom, how often.
 *
 * ⛔⛔ THIS IS NOT ACCOUNTING AND IT NEVER POSTS. It predicts and it reminds.
 * The money is recorded when a human records an expense voucher, because a
 * voucher in this ERP is paid the moment it exists. See the migration.
 */
class RecurringExpense extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'name', 'supplier_id', 'expense_account_id', 'money_account_id',
        'amount', 'amount_varies', 'frequency', 'day_of_month', 'start_date', 'end_date',
        'status', 'reference_prefix', 'lead_days', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_varies' => 'boolean',
            'frequency' => RecurringFrequency::class,
            'status' => RecurringExpenseStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'day_of_month' => 'integer',
            'lead_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'amount', 'frequency', 'day_of_month', 'status', 'expense_account_id', 'money_account_id'])
            ->logOnlyDirty();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringExpenseOccurrence::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RecurringExpenseStatus::Active);
    }

    /**
     * What this costs in a year, at today's expected amount.
     *
     * ⚠ A projection, not a fact. It is what a director asks for - "what are we
     * committed to?" - and it must never be confused with what was spent, which
     * is what the ledger says.
     */
    public function annualCommitment(): float
    {
        return round((float) $this->amount * $this->frequency->perYear(), 2);
    }

    /** The same figure levelled to a month, so unlike frequencies can be added up. */
    public function monthlyRunRate(): float
    {
        return round($this->annualCommitment() / 12, 2);
    }

    /**
     * `NIKOLA-2026-09`. ⛔ Our own convention: Nikola Host issues no numbered
     * invoice, and inventing something shaped like a vendor's number would be
     * claiming a document that does not exist.
     */
    public function referenceFor(CarbonImmutable $due): ?string
    {
        return blank($this->reference_prefix)
            ? null
            : $this->reference_prefix.'-'.$due->format('Y-m');
    }
}
