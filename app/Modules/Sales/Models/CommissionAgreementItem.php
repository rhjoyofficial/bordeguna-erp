<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product, or one whole category (a product family), a commission
 * agreement covers. An agreement with no items covers every product.
 */
class CommissionAgreementItem extends Model
{
    protected $fillable = ['commission_agreement_id', 'product_id', 'category_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
