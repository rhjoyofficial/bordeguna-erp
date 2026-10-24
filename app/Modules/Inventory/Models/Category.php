<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        // C-SKU-1. The SKU segment this category contributes, e.g. DAT, or a
        // family path such as PK-JAR. Stored because it cannot be derived from
        // the name: Nuts is MIX and Seeds is SED.
        'code',
        // ⭐ Phase 2 / 1B (17 Oct 2026): the market pricing defaults every
        // product in this category inherits unless it overrides them. Markup
        // ON COST, minimum gross margin ON SELLING price, rounding step (Tk).
        // Definitions and the formula: Sales\Support\ProductPricing.
        'market_markup_percent',
        'market_min_margin_percent',
        'market_rounding_step',
    ];

    protected function casts(): array
    {
        return [
            'market_markup_percent' => 'decimal:4',
            'market_min_margin_percent' => 'decimal:4',
            'market_rounding_step' => 'decimal:2',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['category_id'];
    }

    public function binLabel(): string
    {
        return 'Category '.$this->name;
    }
}
