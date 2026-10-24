<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One time a market product was sold, or priced, below its minimum selling
 * price (Joy, 25 Oct 2026, Q16 = A): who allowed it, the price, the floor, the
 * reason and the document. ⚠ Written once and never changed or removed.
 */
class PriceFloorOverride extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'product_id',
        'document_type',
        'document_id',
        'unit_price',
        'floor',
        'reason',
        'allowed_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'floor' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A price floor override is a record of what happened and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A price floor override is a record of what happened and cannot be removed.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function allowedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allowed_by');
    }
}
