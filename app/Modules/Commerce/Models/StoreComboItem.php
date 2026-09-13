<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in a bundle, and how many of it.
 */
class StoreComboItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'store_combo_id',
        'product_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(StoreCombo::class, 'store_combo_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
