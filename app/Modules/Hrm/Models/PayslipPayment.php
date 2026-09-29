<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment event against a payslip (DIS-01/DIS-02).
 *
 * Append-only. A payment that bounced is not edited into a failure — the
 * failure is its own row, and so is the reversal that takes the money back off
 * the total. What has actually been paid is `paidTotalFor()`, a sum, which is
 * why no payslip carries a "paid" column that could drift away from its
 * payments.
 *
 * `amount` is always positive and the event type decides the direction, the
 * same guard `PayrollInput::earning()`/`::deduction()` uses in the engine: a
 * stray minus sign should not be able to turn a reversal into a second payment.
 */
class PayslipPayment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'payslip_id',
        'employee_id',
        'disbursement_batch_id',
        'event_type',
        'method',
        // Which bank, cash box or wallet this payment left from. The batch has
        // always recorded it; the individual payment did not, so the two paths
        // left different records and only one reached the ledger.
        'money_account_id',
        'amount',
        'paid_on',
        'reference',
        'failure_reason',
        'reverses_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => PaymentEventType::class,
            'method' => SalaryPaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class, 'disbursement_batch_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What this event contributed to the paid total — positive for a payment,
     * negative for a reversal, nothing at all for a failure.
     */
    public function signedAmount(): float
    {
        return round((float) $this->amount * $this->event_type->signedFactor(), 2);
    }

    public function scopeSettling(Builder $query): Builder
    {
        return $query->whereIn('event_type', [PaymentEventType::Paid, PaymentEventType::Reversed]);
    }

    /**
     * What a payslip has actually been paid.
     *
     * Deliberately a query rather than a column: DIS-02 allows a payment to
     * fail, to be retried and to be reversed, and every one of those is a new
     * row. A cached total would have to be right after each of them.
     */
    public static function paidTotalFor(int $payslipId): float
    {
        $rows = static::withoutGlobalScopes()
            ->where('payslip_id', $payslipId)
            ->settling()
            ->get(['event_type', 'amount']);

        return round($rows->sum(fn (self $payment) => $payment->signedAmount()), 2);
    }
}
