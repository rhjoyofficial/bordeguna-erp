<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'business_id',
        'name',
        'abbreviation',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
