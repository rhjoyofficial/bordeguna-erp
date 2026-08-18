<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
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
    ];

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
            'unit_cost' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
