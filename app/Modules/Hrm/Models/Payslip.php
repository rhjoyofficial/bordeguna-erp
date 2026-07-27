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

    public function paymentStatus(): PayslipPaymentStatus
    {
        return PayslipPaymentStatus::resolve($this->paidTotal(), (float) $this->net);
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
