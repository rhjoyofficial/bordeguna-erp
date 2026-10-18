<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'quantity_invoiced',
        'quantity_returned',
        'unit_cost',
        'line_total_paisa',
        'entry_mode',
        // ⭐ Purchase redesign PR1 (18 Oct 2026). `quantity_ordered`,
        // `unit_cost` and `line_total_paisa` keep their meaning: the quantity
        // in the PURCHASE unit and the value GRNI and the bill read. These say
        // what was typed and how that value was reached.
        'description',
        'unit_id',
        'entered_quantity',
        'entered_rate',
        'gross_paisa',
        'discount_type',
        'discount_value',
        'discount_paisa',
        'order_discount_paisa',
        'tax_rate',
        'tax_amount',
    ];

    /** The stored total as "1234.56", for every screen that lists the line. */
    protected $appends = ['line_total'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            // ⚠ The third quantity. Ordered, received and invoiced are allowed
            // to disagree - that disagreement is the normal state of a purchase
            // and is exactly what Goods Received Not Invoiced reports on.
            'quantity_invoiced' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            // ⚠ Six places since 17 Oct 2026: when the total was typed the rate
            // is total / quantity, shown to six places and never multiplied
            // back (PurchaseLineEntry).
            'unit_cost' => 'decimal:6',
            'line_total_paisa' => 'integer',
            'entry_mode' => PurchaseEntryMode::class,
            'entered_quantity' => 'decimal:4',
            'entered_rate' => 'decimal:6',
            'gross_paisa' => 'integer',
            'discount_value' => 'decimal:4',
            'discount_paisa' => 'integer',
            'order_discount_paisa' => 'integer',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
        ];
    }

    /**
     * ⚠ Compatibility before constraint (17 Oct 2026). `line_total_paisa` is
     * NOT NULL, and a line written by anything other than CreatePurchaseOrder
     * (a seeder, a factory, a test building its own order) still carries only
     * a quantity and a rate. That is a rate entry, and its total is what the
     * backfill gives such a line: quantity x rate through Money, filled here
     * so no writer can leave a line with a hole where its money should be.
     */
    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->line_total_paisa === null && $line->quantity_ordered !== null && $line->unit_cost !== null) {
                $line->line_total_paisa = Money::ofProduct((string) $line->quantity_ordered, (string) $line->unit_cost)->minor;
                $line->entry_mode ??= PurchaseEntryMode::Rate;
            }
        });
    }

    /**
     * What this line is worth in full: the stored total, which is the truth
     * whichever way it was typed.
     *
     * ⚠ Falls back to quantity x rate only for a row the backfill has not
     * reached (the window between the two 17 Oct 2026 migrations).
     */
    public function lineTotal(): Money
    {
        return $this->line_total_paisa === null
            ? Money::ofProduct((string) $this->quantity_ordered, (string) $this->unit_cost)
            : Money::ofMinor((int) $this->line_total_paisa);
    }

    public function getLineTotalAttribute(): string
    {
        return $this->lineTotal()->toTaka();
    }

    /**
     * What a QUANTITY of this line is worth: the figure the supplier will
     * invoice for it, which is what GRNI is credited and relieved with.
     *
     * ⚠⚠ THE FULL QUANTITY IS THE STORED TOTAL, EXACTLY. 3,000 units bought
     * for 100,000 and received at once put 100,000.00 into GRNI, not the
     * 99,999.90 that 3,000 x 33.3333 gives; that gap was the whole reason for
     * storing the total (17 Oct 2026).
     *
     * ⚠ A part: for a line typed by rate, quantity x rate, exactly as every
     * receipt, bill and return valued it before this column existed, so no
     * historical figure moves. For a line typed by total, the proportional
     * share of the total rounded to the paisa (1,000 of the 3,000 is
     * 33,333.33); the parts may differ from the total by a paisa, which the
     * last receipt or bill makes visible as a GRNI rounding, as it always did
     * for converted products.
     */
    public function valueOf(string $quantity): Money
    {
        if ($this->line_total_paisa === null) {
            return Money::ofProduct($quantity, (string) $this->unit_cost);
        }

        if (PurchaseLineEntry::scaled($quantity) === PurchaseLineEntry::scaled((string) $this->quantity_ordered)) {
            return $this->lineTotal();
        }

        return $this->entry_mode === PurchaseEntryMode::Total
            ? PurchaseLineEntry::shareOf($this->lineTotal(), $quantity, (string) $this->quantity_ordered)
            : Money::ofProduct($quantity, (string) $this->unit_cost);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** ⭐ PR1: the unit the line was typed in (null = the purchase unit). */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /** ⭐ PR1: the line's own discount plus its share of the order discount. */
    public function totalDiscount(): Money
    {
        return Money::ofMinor((int) $this->discount_paisa + (int) $this->order_discount_paisa);
    }

    /**
     * ⭐ PR1: the part of the line's discount a QUANTITY of it carries, which
     * the bill opens with as its trade discount. The whole quantity carries
     * the whole discount, exactly.
     */
    public function discountFor(string $quantity): Money
    {
        $total = $this->totalDiscount();

        if ($total->isZero() || PurchaseLineEntry::scaled($quantity) >= PurchaseLineEntry::scaled((string) $this->quantity_ordered)) {
            return $total;
        }

        return PurchaseLineEntry::shareOf($total, $quantity, (string) $this->quantity_ordered);
    }

    public function quantityRemaining(): string
    {
        return (string) ((float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    /**
     * Net received-and-not-yet-returned quantity — the ceiling on how much
     * more can be returned, and what must reach zero before the owning
     * order can be cancelled after receiving anything.
     */
    public function quantityReturnable(): string
    {
        return (string) ((float) $this->quantity_received - (float) $this->quantity_returned);
    }

    /**
     * What can still be put on a supplier bill for this line.
     *
     * ⚠⚠ D-SB-8. RECEIVED MINUS RETURNED MINUS ALREADY INVOICED. Goods that came
     * in and went back are not billable, and comparing against arrivals alone
     * let a delivery that was returned in full still be invoiced in full.
     *
     * ⚠ Never negative. A line whose returns exceed what is still uninvoiced
     * reads as zero rather than as a negative allowance somebody could bill
     * against.
     */
    public function billableQuantity(): string
    {
        $scale = fn (string $value) => (int) round(((float) $value) * 10000);

        $billable = $scale((string) $this->quantity_received)
            - $scale((string) $this->quantity_returned)
            - $scale((string) $this->quantity_invoiced);

        return number_format(max(0, $billable) / 10000, 4, '.', '');
    }

    /**
     * ⚠ Purchasing had no equivalent of the sales side's uninvoiced scope, so
     * every screen that wanted "what is still billable" would have written this
     * comparison itself, differently each time.
     */
    public function scopeStillBillable(Builder $query): Builder
    {
        return $query->whereRaw('quantity_received - quantity_returned > quantity_invoiced');
    }
}
