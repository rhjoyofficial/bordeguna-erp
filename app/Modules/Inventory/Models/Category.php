<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        // C-SKU-1. The SKU segment this category contributes, e.g. DAT, or a
        // family path such as PK-JAR. Stored because it cannot be derived from
        // the name: Nuts is MIX and Seeds is SED.
        'code',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
