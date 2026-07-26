<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One planned repayment (ADJ-07's "installment" and "start month").
 *
 * A plan, not a payment. It gives each deduction the stable installment number
 * that appears on the payslip — "3 of 12" — and it is what payroll looks up to
 * find what is due. Whether the money actually moved is the ledger's answer, and
 * `payslip_id` is the join between the two: set only once a run was disbursed.
 *
 * The final installment carries the remainder rather than the round figure, so
 * twelve payments of 4,166.67 against a 50,000 principal do not quietly leave
 * four paisa outstanding forever.
 */
class LoanInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'sequence',
        'year',
        'month',
        'amount',
        'status',
        'paid_at',
        'payslip_id',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
            'status' => LoanInstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isDue(): bool
    {
        return $this->status->isDue();
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', LoanInstallmentStatus::Scheduled);
    }

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }
}
