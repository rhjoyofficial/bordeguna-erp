<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A credit note against an issued sales invoice (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.2 and 4.4): its own CN number, citing the invoice's number
 * and date. The invoice itself never changes (FINAL-PLAN 6.6 rule 1).
 *
 *   full    the whole invoice taken back: its journal mirrored on the date the
 *           correction rules give (`journal_id` = that mirror), the invoice
 *           marked credited, its challan lines billable again. Raised by the
 *           corrections process (Reverse = "Credit note", Correct = credit note
 *           + the new invoice, Void sale at the counter).
 *   price   a lower price on goods the customer keeps: Dr 4200 Sales Returns
 *           and Allowances / Cr 1140 (customer), carried by a party adjustment
 *           allocated to the invoice, so the ageing, the statement and the
 *           reconciliation read it like any other reduction of what is owed.
 *
 * ⚠ Posted documents are never deleted (owner's rule); this one has no
 * edit path at all.
 */
class SalesCreditNote extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const FULL = 'full';

    public const PRICE = 'price';

    protected $fillable = [
        'business_id',
        'credit_note_number',
        'sales_invoice_id',
        'customer_id',
        'kind',
        'credit_date',
        'amount',
        'reason',
        'journal_id',
        'party_adjustment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date:Y-m-d',
            'amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['credit_note_number', 'sales_invoice_id', 'kind', 'credit_date', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function partyAdjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function isFull(): bool
    {
        return $this->kind === self::FULL;
    }

    /** "Credit note CN-2026-27-0003 on INV-2026-27-0012 of 14/10/2026". */
    public function label(): string
    {
        $this->loadMissing('invoice:id,invoice_number,invoice_date');

        return "Credit note {$this->credit_note_number} on {$this->invoice?->invoice_number} of {$this->invoice?->invoice_date?->format('d/m/Y')}";
    }
}
