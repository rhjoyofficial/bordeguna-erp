<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ⭐ B17 (25 Oct 2026): a Manager or the Owner let a document past a stock
 * reservation, with a reason. One row each time, never edited:
 *
 *   backorder      an order confirmed for more than is available; the
 *                  shortfall stays unreserved and is listed
 *   take_reserved  a counter bill or a direct challan took stock held for an order
 *   release        a hold ended by hand on the Reserved stock list
 */
class StockReservationOverride extends Model
{
    use BelongsToBusiness;

    public const BACKORDER = 'backorder';

    public const TAKE_RESERVED = 'take_reserved';

    public const RELEASE = 'release';

    protected $fillable = [
        'business_id',
        'kind',
        'product_id',
        'warehouse_id',
        'quantity',
        'available',
        'reference_type',
        'reference_id',
        'reason',
        'authorised_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'available' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
