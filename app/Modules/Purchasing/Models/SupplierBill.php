<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\PartyAdjustmentAllocation;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A supplier's own invoice - the Accounts Payable document.
 *
 * ⚠ Its number and its dates belong to the supplier. Ours are `id` and
 * `created_at`, and keeping both is what lets a bill arriving in August belong
 * to July.
 */
class SupplierBill extends Model
{
    use BelongsToBusiness, HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'purchase_order_id',
        'bill_number',
        'bill_date',
        'due_date',
        'total',
        'is_opening',
        'note',
        'reversed_at',
        'reversed_by',
        'reversal_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'reversed_at' => 'datetime',
            'total' => 'decimal:4',
            'is_opening' => 'boolean',
        ];
    }

    /**
     * ⚠ PUR-004 (owner decision 14 Oct 2026): `active_number_key` is the bill
     * number while the bill is live and NULL once it is reversed, so the
     * database's unique index refuses a second LIVE bill with one supplier
     * number but lets the corrected bill reuse a reversed bill's number. Set on
     * every save, so no path that writes a bill can forget it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $bill): void {
            $bill->active_number_key = $bill->reversed_at === null ? $bill->bill_number : null;
        });
    }

    /**
     * ⚠ D-SB-6. A reversed bill is kept, not deleted, so every screen and every
     * guard asks this rather than checking for absence.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'purchase_order_id', 'bill_number', 'bill_date', 'due_date', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function creditAllocations(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteAllocation::class);
    }

    /** Discounts and set-offs allocated to this bill (29 Sep 2026). */
    public function adjustmentAllocations(): HasMany
    {
        return $this->hasMany(PartyAdjustmentAllocation::class, 'supplier_bill_id');
    }

    /**
     * ⚠ DERIVED from the allocations, never stored on the bill.
     *
     * An `amount_paid` column would be a second source of truth for a figure the
     * allocations already answer, and it would drift the first time a payment
     * was recorded by a path that forgot to update it. This project spent four
     * Inventory slices removing exactly that shape from stock quantities.
     */
    public function paidTotal(): Money
    {
        return Money::ofTaka((string) ($this->allocations()->sum('amount') ?: '0'));
    }

    /**
     * What the supplier's credit notes have knocked off this bill.
     */
    public function creditedTotal(): Money
    {
        return Money::ofTaka((string) ($this->creditAllocations()->sum('amount') ?: '0'));
    }

    /**
     * What discounts and set-offs (party adjustments) have knocked off this bill.
     */
    public function adjustedTotal(): Money
    {
        return Money::ofTaka((string) ($this->adjustmentAllocations()->sum('amount') ?: '0'));
    }

    public function outstanding(): Money
    {
        // ⚠⚠ A REVERSED BILL OWES NOTHING. Its journal has been reversed, so
        // Accounts Payable no longer holds it, and a reversal is refused once
        // anything was paid or credited, so there is no settled part to report
        // either. Until 21 Sep 2026 this still returned the full total, which
        // put a reversed bill on the "Pay a supplier" form, in the supplier's
        // amount owed, and let RecordSupplierPayment pay it: a debit to Accounts
        // Payable with no credit behind it. The cockpit and cash forecast
        // already left reversed bills out; this makes the document agree.
        if ($this->isReversed()) {
            return Money::zero();
        }

        return Money::ofTaka((string) $this->total)
            ->plus($this->paidTotal()->negated())
            ->plus($this->creditedTotal()->negated())
            ->plus($this->adjustedTotal()->negated());
    }

    public function isSettled(): bool
    {
        return $this->outstanding()->minor <= 0;
    }

    /**
     * Bills that still owe money, with what each owes as `outstanding_paisa`,
     * in one query: outstanding() asked in SQL.
     *
     * ⚠ PUR-008 (audit 14 Oct 2026): the payment form called outstanding() on
     * every bill of every supplier ever recorded, three SUM queries a bill. A
     * reversed bill owes nothing (as outstanding() says), and each figure is
     * rounded to whole paisa where outstanding() rounds it, so the form and
     * the bill page cannot disagree by a fraction of a paisa.
     */
    public function scopeOwingWithOutstanding(Builder $query): Builder
    {
        $bills = $query->getModel()->getTable();
        $paid = (new SupplierPaymentAllocation)->getTable();
        $credited = (new SupplierCreditNoteAllocation)->getTable();
        $adjusted = (new PartyAdjustmentAllocation)->getTable();

        $outstanding = "(ROUND({$bills}.total * 100)"
            ." - COALESCE((SELECT ROUND(SUM(p.amount) * 100) FROM {$paid} p WHERE p.supplier_bill_id = {$bills}.id), 0)"
            ." - COALESCE((SELECT ROUND(SUM(c.amount) * 100) FROM {$credited} c WHERE c.supplier_bill_id = {$bills}.id), 0)"
            ." - COALESCE((SELECT ROUND(SUM(a.amount) * 100) FROM {$adjusted} a WHERE a.supplier_bill_id = {$bills}.id), 0))";

        if ($query->getQuery()->columns === null) {
            $query->select("{$bills}.*");
        }

        return $query->whereNull("{$bills}.reversed_at")
            ->whereRaw("{$outstanding} > 0")
            ->selectRaw("{$outstanding} as outstanding_paisa");
    }

    /**
     * The VAT on this bill that may be claimed back (VAT plan D7): what goes to
     * Input VAT Receivable. VAT on a line marked not recoverable is part of the
     * cost of the goods and is not counted here. Zero on every untaxed bill.
     */
    public function recoverableTax(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, SupplierBillLine $line) => $line->tax_recoverable
                ? $carry->plus(Money::ofTaka((string) $line->tax_amount))
                : $carry,
            Money::zero(),
        );
    }
}
