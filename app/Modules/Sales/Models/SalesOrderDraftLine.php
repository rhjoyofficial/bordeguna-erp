<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a draft sales order: the SKU, the quantity, and the price the ERP's own engine gave it
 * at that moment, with the engine's explanation. Immutable, like the draft it belongs to.
 */
class SalesOrderDraftLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'sales_order_draft_id', 'product_id', 'sku', 'quantity', 'unit_price', 'line_total',
        'price_note', 'made_to_order',
    ];

    protected function casts(): array
    {
        return ['made_to_order' => 'boolean', 'quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A sales order draft line is immutable.'));
        static::deleting(fn () => throw new LogicException('A sales order draft line is never deleted.'));
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDraft::class, 'sales_order_draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
