<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One returned delivery line, with its inspection split (C-SR-3) and the money
 * exactly as the posting valued it (C-REC-6).
 */
class SalesReturnLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'sales_return_id',
        'delivery_line_id',
        'quantity',
        'quantity_to_stock',
        'quantity_written_off',
        'gdni_relief',
        'cogs_relief',
        'cost_restored',
        'cost_written_off',
        'credit_gross',
        'credit_discount',
        'credit_net',
        'tax_rate',
        'tax_amount',
        'tax_inside_gross',
        'tax_on_discount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_to_stock' => 'decimal:4',
            'quantity_written_off' => 'decimal:4',
            'gdni_relief' => 'decimal:4',
            'cogs_relief' => 'decimal:4',
            'cost_restored' => 'decimal:4',
            'cost_written_off' => 'decimal:4',
            'credit_gross' => 'decimal:4',
            'credit_discount' => 'decimal:4',
            'credit_net' => 'decimal:4',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
    }

    public function movementLinks(): HasMany
    {
        return $this->hasMany(SalesReturnLineMovement::class);
    }

    public function invoiceLineCredits(): HasMany
    {
        return $this->hasMany(SalesReturnInvoiceLine::class);
    }
}
