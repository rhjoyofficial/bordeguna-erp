<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'purchase_return_id',
        'purchase_order_line_id',
        'quantity_returned',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_returned' => 'decimal:4',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * The reversing movement that sent these goods back.
     *
     * ⚠ The COLUMN was added in slice A2 so a return could find its own
     * paperwork, and the relation was never written - so nothing could actually
     * follow it. Found by the posting that needed it: a column with no way to
     * traverse it is half a fix.
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
