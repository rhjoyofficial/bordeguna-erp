<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A customer's advance used on one of their invoices (B11, 24 Oct 2026):
 * Dr 2150 Advances from Customers / Cr 1140 Accounts Receivable, both with the
 * customer as party.
 *
 * ⚠ One advance, one invoice, one row. `customer_advance_id` is NULL for money
 * drawn from the customer's ON-ACCOUNT 2150 balance (Receive vouchers and
 * journals that put money there without an advance document, see
 * CustomerAdvanceBook).
 *
 * ⚠ The invoice is reduced through `party_adjustment_id`, a
 * `customer_advance_applied` adjustment, so every figure that already
 * subtracts discounts and set-offs from an invoice subtracts this too. On
 * reversal it is kept and marked, its allocation released (the wave 2 shape).
 */
class CustomerAdvanceApplication extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    protected $fillable = [
        'business_id',
        'number',
        'customer_id',
        'customer_advance_id',
        'sales_invoice_id',
        'applied_on',
        'amount',
        'party_adjustment_id',
        'source_note',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'applied_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'customer_id', 'customer_advance_id', 'sales_invoice_id', 'applied_on', 'amount'])
            ->dontSubmitEmptyLogs();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(CustomerAdvance::class, 'customer_advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class, 'party_adjustment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function isOnAccount(): bool
    {
        return $this->customer_advance_id === null;
    }
}
