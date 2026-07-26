<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A loan or salary advance (ADJ-07, all options ticked).
 *
 * The agreement only: what was borrowed and on what terms. What is still owed is
 * never stored here — it is the sum of the ledger, so the balance shown on a
 * screen, the balance deducted by payroll and the balance an auditor recomputes
 * are the same number by construction rather than by maintenance.
 *
 * Approvable, so the ADJ-07 request-and-approval step rides on the workflow
 * engine like everything else rather than a bespoke status column.
 */
class Loan extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'reference',
        'type',
        'status',
        'principal',
        'installment_amount',
        'installment_count',
        'start_year',
        'start_month',
        'reason',
        'disbursed_on',
        'settled_at',
        'settlement_note',
        'requested_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => LoanType::class,
            'status' => LoanStatus::class,
            'principal' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'installment_count' => 'integer',
            'start_year' => 'integer',
            'start_month' => 'integer',
            'disbursed_on' => 'date',
            'settled_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_id', 'reference', 'type', 'status', 'principal', 'installment_amount',
                'installment_count', 'start_year', 'start_month', 'reason', 'disbursed_on',
                'settled_at', 'settlement_note', 'applied_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class)->orderBy('occurred_on')->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * What is still owed — the sum of the ledger, never a stored figure.
     *
     * Rounded to 2 decimals at the point of reading rather than accumulated,
     * because every entry that goes in was already rounded when it was written.
     */
    public function outstanding(): float
    {
        $entries = $this->relationLoaded('ledgerEntries')
            ? $this->ledgerEntries->sum('amount')
            : $this->ledgerEntries()->sum('amount');

        return round((float) $entries, 2);
    }

    public function totalRepaid(): float
    {
        return round((float) $this->principal - $this->outstanding(), 2);
    }

    public function isFullyRepaid(): bool
    {
        return $this->outstanding() <= 0.005;
    }

    /**
     * The installment payroll should take for a given month, if any.
     *
     * Only a scheduled row qualifies: one already paid must never be taken
     * twice, and one cancelled by an early settlement is no longer owed.
     */
    public function installmentDueIn(int $year, int $month): ?LoanInstallment
    {
        return $this->installments()
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', LoanInstallmentStatus::Scheduled)
            ->first();
    }

    public function scopeRecoverable(Builder $query): Builder
    {
        return $query->where('status', LoanStatus::Active);
    }

    /**
     * Disbursed. A loan that has not been paid out has no ledger entries and
     * therefore owes nothing, so payroll must never deduct against one.
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * The next reference in sequence for a business. Loans are rare enough that
     * a count-based reference is safe, and the unique index is the real guard.
     */
    public static function nextReference(int $businessId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->value('reference');

        $next = $last === null ? 1 : ((int) preg_replace('/\D/', '', $last)) + 1;

        return 'LOAN-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function startsOn(): Carbon
    {
        return Carbon::create($this->start_year, $this->start_month, 1)->startOfDay();
    }

    public function summaryLine(): string
    {
        return sprintf(
            '%s — %s %s of %s, repaid at %s a month from %s',
            $this->employee?->fullName(),
            $this->type->label(),
            $this->reference,
            number_format((float) $this->principal, 2),
            number_format((float) $this->installment_amount, 2),
            $this->startsOn()->format('F Y'),
        );
    }
}
