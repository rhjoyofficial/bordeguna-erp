<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'is_default',
        'is_active',
    ];

    /**
     * ⚠ The database defaults, repeated on the model. A column default only
     * applies once the row is written, so a freshly created Warehouse instance
     * has a NULL `is_active` and any caller checking `! $warehouse->is_active`
     * refuses a warehouse the database considers active.
     *
     * Latent until Sales slice 2 asked the question on 2026-08-04. Same
     * convention as Account, MoneyAccount and Customer.
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }
}
