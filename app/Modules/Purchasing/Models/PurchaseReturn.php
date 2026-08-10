<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use BelongsToBusiness, HasFactory;

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
                    : Money::ofProduct((string) $line->quantity_returned, (string) $poLine->unit_cost));
            }, Money::zero());
    }

    /**
     * How much of this return's value the supplier has already credited.
     */
    public function creditedTotal(): Money
    {
        return Money::ofTaka((string) (SupplierCreditNote::query()
            ->where('purchase_return_id', $this->id)
            ->sum('total') ?: '0'));
    }
}
