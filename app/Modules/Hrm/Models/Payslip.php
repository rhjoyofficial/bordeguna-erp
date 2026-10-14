<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Enums\PayslipPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's payslip within a run.
 *
 * `calculation` stores the engine's own derivation rather than something to be
 * re-derived on demand: after a policy or salary revision, re-running the maths
 * would produce today's answer, not the one that was actually paid. PAY-12 and
 * PAY-13 both depend on that distinction.
 */
class Payslip extends Model
{
    use BelongsToBusiness, HasFactory;

    /** Memo for paidOnOtherVersions(); one query per payslip per request. */
    private ?float $otherVersionsPaid = null;

    protected $fillable = [
        'business_id',
        'payroll_run_id',
        'employee_id',
        'gross_salary',
        'total_earnings',
        'total_deductions',
        'net',
        'is_blocked',
        'block_reasons',
        'calculation',
        'salary_structure_id',
        'emailed_at',
        'emailed_to',
        'email_error',
    ];

    protected function casts(): array
    {
        return [
            'gross_salary' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'is_blocked' => 'boolean',
            'block_reasons' => 'array',
            'calculation' => 'array',
            'emailed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sequence')->orderBy('id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayslipPayment::class)->orderBy('id');
    }

    /**
     * Modelled as many-to-many because the pivot is where the unique index
     * lives; a payslip belongs to at most one batch, and `batch()` is how to
     * ask for it.
     */
    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(DisbursementBatch::class, 'disbursement_batch_payslip');
    }

    public function batch(): ?DisbursementBatch
    {
        return $this->relationLoaded('batches')
            ? $this->batches->first()
            : $this->batches()->first();
    }

    /**
     * What has actually reached the employee (DIS-01/02).
     *
     * A sum of payment events rather than a stored figure. Prefers the loaded
     * relation when the caller has already eager-loaded it, so presenting a
     * whole run's payslips does not fire a query per row.
     */
    public function paidTotal(): float
    {
        if ($this->relationLoaded('payments')) {
            return round($this->payments
                ->filter(fn (PayslipPayment $p) => $p->event_type->signedFactor() !== 0)
                ->sum(fn (PayslipPayment $p) => $p->signedAmount()), 2);
        }

        return PayslipPayment::paidTotalFor($this->id);
    }

    /**
     * What payments on OTHER versions of this month have already delivered to
     * this employee (HRM-002, audit 14 Oct 2026).
     *
     * ⚠⚠ ONE MONTH IS PAID ONCE, WHATEVER ITS VERSION. A WFL-08 unlock opens a
     * new version whose payslips are new rows with no payments of their own,
     * so "net less what this row was paid" showed the whole month outstanding
     * again and the payment guard let it be paid a second time. The money paid
     * against the locked version is still money paid. Counted across every
     * other version of the period, exactly as SettlementFigures::unpaidSalarySql
     * already counts it for the leaver statement, so the two never disagree.
     * The accrual side already balances this way: the superseded version's
     * accrual is reversed when the new one is approved, so paying only the
     * difference leaves Wages Payable at zero.
     */
    public function paidOnOtherVersions(): float
    {
        if ($this->otherVersionsPaid !== null) {
            return $this->otherVersionsPaid;
        }

        $periodId = PayrollRun::query()->withoutGlobalScopes()
            ->whereKey($this->payroll_run_id)
            ->value('payroll_period_id');

        if ($periodId === null) {
            return $this->otherVersionsPaid = 0.0;
        }

        $rows = PayslipPayment::withoutGlobalScopes()
            ->settling()
            ->whereIn('payslip_id', static::query()->withoutGlobalScopes()
                ->select('payslips.id')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payroll_runs.payroll_period_id', $periodId)
                ->where('payslips.employee_id', $this->employee_id)
                ->where('payslips.id', '!=', $this->id))
            ->get(['event_type', 'amount']);

        return $this->otherVersionsPaid = round($rows->sum(fn (PayslipPayment $payment) => $payment->signedAmount()), 2);
    }

    /**
     * Everything that has reached the employee for this month: this payslip's
     * own payments plus those made against its other versions (HRM-002).
     */
    public function settledTotal(): float
    {
        return round($this->paidTotal() + $this->paidOnOtherVersions(), 2);
    }

    /**
     * What is still to pay on this payslip, never below zero. ONE figure for
     * the screen, the batch, the bank file and the payment guard (HRM-002):
     * five places used to work it out by hand from this row's payments alone.
     *
     * ⚠ A new version that came out LOWER than what was already paid shows
     * zero here, not a negative: the overpayment is real but recovering it is
     * a human decision (a deduction next month), not something to invent.
     */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->net - $this->settledTotal(), 2));
    }

    public function paymentStatus(): PayslipPaymentStatus
    {
        return PayslipPaymentStatus::resolve($this->settledTotal(), (float) $this->net);
    }

    /**
     * The most recent failure still standing against this payslip — DIS-02's
     * "unpaid status and retry" needs the reason visible, not merely the gap.
     * A payment recorded after a failure clears it: the retry succeeded.
     */
    public function currentFailure(): ?PayslipPayment
    {
        $last = $this->relationLoaded('payments')
            ? $this->payments->last()
            : $this->payments()->latest('id')->first();

        return $last?->event_type === PaymentEventType::Failed ? $last : null;
    }

    /**
     * The engine's plain-language account of how this payslip was reached.
     *
     * @return array<int, string>
     */
    public function explanation(): array
    {
        return $this->calculation['notes'] ?? [];
    }
}
