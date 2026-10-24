<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): Reversible. A reversed return put its
 * goods back into the batches they left, at the same cost, and its GRNI entry
 * is mirrored; it is kept and marked, and it can no longer be credited.
 *
 * ⭐ 24 Oct 2026 (wave 1 gaps): LogsActivity, so its History tab shows when it
 * was recorded and reversed, with the reason (FINAL-PLAN 6.2: logging is added
 * to the records that lack it, purchase returns named among them).
 */
class PurchaseReturn extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity, Reversible;

    /** "Purchase return #12", as the screens and the journals name it. */
    public function label(): string
    {
        return "Purchase return #{$this->id}";
    }

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'returned_by',
        'returned_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /**
     * What this return is worth in the supplier's invoicing terms - the exact
     * figure its posting debited GRNI with, and therefore the ceiling on any
     * credit note recorded against it (C-SCN-2).
     *
     * ⚠ Reproduces PostPurchaseJournals::forReturn's expression line by line,
     * including the skip conditions: a line with no movement contributed
     * nothing to the posting, so it contributes nothing to the cap.
     */
    public function invoiceableValue(): Money
    {
        return $this->lines()->with(['stockMovement', 'purchaseOrderLine'])->get()
            ->reduce(function (Money $carry, PurchaseReturnLine $line) {
                $movement = $line->stockMovement;

                if ($movement === null) {
                    return $carry;
                }

                $stockValue = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

                if ($stockValue->isZero()) {
                    return $carry;
                }

                $poLine = $line->purchaseOrderLine;

                return $carry->plus($poLine === null
                    ? $stockValue
                    : $poLine->valueOf((string) $line->quantity_returned));
            }, Money::zero());
    }

    /**
     * How much of this return's value the supplier has already credited.
     */
    public function creditedTotal(): Money
    {
        $notes = Money::ofTaka((string) (SupplierCreditNote::query()
            ->where('purchase_return_id', $this->id)
            // PUR-003 (14 Oct 2026): a reversed credit note credits nothing.
            ->whereNull('reversed_at')
            ->sum('total') ?: '0'));

        // ⭐ Initial plan B9 (Joy's Q21, 25 Oct 2026): the part of the return
        // a paid bill could not take, kept as a supplier advance, is credited
        // too, so nothing credits the same goods twice.
        $advances = Money::ofTaka((string) (SupplierAdvance::query()
            ->where('purchase_return_id', $this->id)
            ->whereNull('reversed_at')
            ->sum('amount') ?: '0'));

        return $notes->plus($advances);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['purchase_order_id', 'returned_at', 'note', 'reversed_on', 'reversal_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
