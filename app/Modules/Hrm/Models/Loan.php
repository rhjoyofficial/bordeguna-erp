<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Support\FrozenAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        // Which bank, cash box or wallet the principal was handed over from.
        // Nullable until disbursement; required at it once accounting is on.
        'money_account_id',
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
            'disbursed_on' => 'date:Y-m-d',
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

    /**
     * The installment for this month that ANOTHER version of the same payroll
     * already recovered (HRM-002, audit 14 Oct 2026).
     *
     * ⚠⚠ An unlocked month is recalculated as a new version, but the locked
     * version's disbursement already marked the installment Paid, and
     * installmentDueIn() rightly never offers a Paid row. Without this the new
     * version was calculated WITHOUT the loan line, so its net came out higher
     * by the installment: the employee kept money the locked version had
     * already taken. The deduction belongs on every version of the month; the
     * ledger repayment stays the one the first version posted (it is keyed per
     * installment, and PostLoanRepayments skips a row that is no longer due).
     */
    public function installmentRecoveredIn(PayrollPeriod $period): ?LoanInstallment
    {
        return $this->installments()
            ->where('year', (int) $period->year)
            ->where('month', (int) $period->month)
            ->where('status', LoanInstallmentStatus::Paid)
            ->whereIn('payslip_id', Payslip::query()->withoutGlobalScopes()
                ->select('payslips.id')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payroll_runs.payroll_period_id', $period->id)
                ->where('payslips.employee_id', $this->employee_id))
            ->first();
    }

    /**
     * Every installment of this loan that ANY version of this month already
     * recovered, whatever month it was scheduled in. Rule #21 (14 Oct 2026)
     * extends HRM-002 to an installment carried in from an earlier month: it
     * too must stay on a recalculated version of the month that took it.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsRecoveredIn(PayrollPeriod $period): Collection
    {
        return $this->installments()
            ->where('status', LoanInstallmentStatus::Paid)
            ->whereIn('payslip_id', Payslip::query()->withoutGlobalScopes()
                ->select('payslips.id')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payroll_runs.payroll_period_id', $period->id)
                ->where('payslips.employee_id', $this->employee_id))
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Rule #21 (approved 14 Oct 2026): installments that fell in an EARLIER
     * month whose payroll was already Approved, Disbursed or Locked without
     * them, so that month will never collect them. They are carried into the
     * next open payroll and deducted there, once.
     *
     * Not carried:
     *  - a month whose payroll is still open (it will collect its own);
     *  - a month with no payroll at all (nothing was approved without it);
     *  - an installment already on a payslip of another month's current
     *    version (an approved-but-unpaid month that deducts it, or a later
     *    month that already carried it), so two months never take one row.
     *
     * Exactly once also rests on the rows themselves: disbursing the month that
     * carried it marks it Paid (PostLoanRepayments reads the line's source),
     * and a Paid row is never offered again.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsCarriedInto(PayrollPeriod $period): Collection
    {
        $index = (int) $period->year * 12 + (int) $period->month;

        return $this->installments()
            ->where('status', LoanInstallmentStatus::Scheduled)
            ->orderBy('year')->orderBy('month')->orderBy('sequence')
            ->get()
            ->filter(fn (LoanInstallment $installment) => (int) $installment->year * 12 + (int) $installment->month < $index)
            ->filter(function (LoanInstallment $installment) {
                $status = PayrollPeriod::query()
                    ->where('year', $installment->year)
                    ->where('month', $installment->month)
                    ->first()?->latestRun()?->status;

                return $status !== null && FrozenAttendance::freezes($status);
            })
            ->reject(fn (LoanInstallment $installment) => self::claimedElsewhere($installment, $period))
            ->values();
    }

    /**
     * Rule #21: is this installment already deducted on the current, live
     * version of ANOTHER month's payroll? Superseded and voided versions do
     * not count; they will never be paid.
     */
    public static function claimedElsewhere(LoanInstallment $installment, PayrollPeriod $period): bool
    {
        return PayslipLine::query()
            ->where('source', "loan_installment:{$installment->id}")
            ->with('payslip.run')
            ->get()
            ->contains(function (PayslipLine $line) use ($period) {
                $run = $line->payslip?->run;

                if ($run === null || (int) $run->payroll_period_id === (int) $period->id || $run->status->isVoided()) {
                    return false;
                }

                return (int) $run->period?->latestRun()?->id === (int) $run->id;
            });
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
