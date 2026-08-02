<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A group of payslips paid together by one method (DIS-01).
 *
 * DIS-01 ticked every option it offered, and a batch is what makes them one
 * mechanism rather than four:
 *
 *  - **whole batch paid** — confirm the batch and every payslip in it is paid;
 *  - **employee-wise confirmation** — or confirm them one at a time, since the
 *    payments are per payslip either way;
 *  - **cash and bank separate** — the method sits on the batch, so a run
 *    produces one batch per method and they are never mixed;
 *  - **bank advice upload/confirmation** — the advice file is an attachment and
 *    the reference the bank returned is `advice_reference`.
 *
 * The batch stores no totals. What it collected is the sum of its payments, for
 * the same reason a loan balance is the sum of its ledger: a stored total is a
 * second version of the truth, waiting to disagree with the first.
 */
class DisbursementBatch extends Model
{
    use BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    /** Attachment collection holding the bank advice file or its confirmation. */
    public const ADVICE_COLLECTION = 'bank_advice';

    protected $fillable = [
        'business_id',
        'payroll_run_id',
        'reference',
        'method',
        'note',
        'advice_reference',
        'money_account_id',
        'confirmed_at',
        'confirmed_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => SalaryPaymentMethod::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayslipPayment::class);
    }

    /**
     * The payslips this batch covers, fixed at creation.
     */
    public function payslips(): BelongsToMany
    {
        return $this->belongsToMany(Payslip::class, 'disbursement_batch_payslip');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * What this batch has actually moved — paid less reversed, failures
     * contributing nothing.
     */
    public function paidTotal(): float
    {
        return round($this->payments()
            ->whereIn('event_type', [PaymentEventType::Paid, PaymentEventType::Reversed])
            ->get(['event_type', 'amount'])
            ->sum(fn (PayslipPayment $payment) => $payment->signedAmount()), 2);
    }

    public function advice(): ?Attachment
    {
        return $this->attachmentInCollection(self::ADVICE_COLLECTION);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'method', 'advice_reference', 'confirmed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
