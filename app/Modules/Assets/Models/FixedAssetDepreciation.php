<?php

namespace App\Modules\Assets\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's wear on one asset.
 *
 * ⚠ The charge row is the record; the journal follows it. Together with the
 * unique index on (asset, year, month) it is what makes running the command
 * twice in a month harmless.
 */
class FixedAssetDepreciation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'fixed_asset_id',
        'year',
        'month',
        'charged_on',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'charged_on' => 'date',
            'amount' => 'decimal:4',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
