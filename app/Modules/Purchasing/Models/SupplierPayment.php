<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money actually leaving the business for a supplier.
 *
 * ⚠ The document comes first and the journal follows it. A payment is a thing
 * that happened - somebody moved money on a date from an account with a
 * reference - and the accounting entry is a consequence, not the point.
 */
class SupplierPayment extends Model
{
    // PUR-003 (14 Oct 2026): corrected by reversing it, never edited.
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'money_account_id',
        // The voucher that created this payment (finance decision C3), if any.
        'voucher_id',
        'paid_on',
        'amount',
        'tds_rate_id',
        'tds_amount',
        'vds_rate_id',
        'vds_amount',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'tds_amount' => 'decimal:4',
            'vds_amount' => 'decimal:4',
            // PUR-003: the bills it had settled, kept once it is reversed.
            'reversed_allocations' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'money_account_id', 'paid_on', 'amount', 'reference'])
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What actually left the money account: the amount settled less the tax held back. */
    public function netPaid(): Money
    {
        return Money::ofMinor(
            Money::ofTaka((string) $this->amount)->minor
            - Money::ofTaka((string) $this->tds_amount)->minor
            - Money::ofTaka((string) $this->vds_amount)->minor
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * ⭐ Initial plan B2 (R57, 25 Oct 2026): the accounts a SPLIT payment came
     * from. Empty for a payment from one account.
     */
    public function moneyLines(): HasMany
    {
        return $this->hasMany(SupplierPaymentMoneyLine::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * ⭐ B2: what left each money account, split or not: the rows of a split
     * payment, or the one account with the net paid plus the net of any
     * overpayment kept as an advance (one remittance, one bank line).
     *
     * @return list<array{account: MoneyAccount, amount: Money}>
     */
    public function cashLines(): array
    {
        $lines = $this->relationLoaded('moneyLines') ? $this->moneyLines : $this->moneyLines()->with('moneyAccount')->get();

        if ($lines->isNotEmpty()) {
            return $lines->map(fn (SupplierPaymentMoneyLine $line) => ['account' => $line->moneyAccount, 'amount' => $line->money()])->values()->all();
        }

        $advance = SupplierAdvance::query()->where('supplier_payment_id', $this->id)->where('kind', 'overpayment')->first();

        return [['account' => $this->moneyAccount, 'amount' => $this->netPaid()->plus($advance?->netPaid() ?? Money::zero())]];
    }

    /** ⭐ B2: "BRAC Bank" or "BRAC Bank, bKash" for lists and messages. */
    public function paidFromLabel(): string
    {
        return collect($this->cashLines())->map(fn (array $line) => $line['account']?->name)->filter()->implode(', ');
    }

    /**
     * ⚠ Derived, never stored. An "unallocated" column would be a second figure
     * that drifts the first time an allocation is added, and the allocations
     * themselves are the record of what this payment settled.
     */
    public function allocatedTotal(): string
    {
        return (string) $this->allocations()->sum('amount');
    }
}
