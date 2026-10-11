<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What the ERP said a parcel would cost when it was booked: the inputs, the
 * rate-card version, the method, the figure and how sure it was.
 *
 * ⚠⚠ WRITTEN ONCE (ChatGPT ruling 8). It is one of three facts beside the
 * courier's quote and the statement's actual charge, and none overwrites
 * another. Updating or deleting it throws; a later estimate is a new row.
 */
class CourierFeeEstimate extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    public const PROVIDER_QUOTE = 'provider_quote';

    public const RATE_CARD = 'rate_card';

    public const HISTORICAL = 'historical';

    public const INSUFFICIENT = 'insufficient';

    protected $fillable = [
        'business_id', 'delivery_id', 'consignment_id', 'courier_account_id', 'provider', 'method',
        'courier_rate_card_id', 'rate_card_version', 'from_band', 'to_band', 'service', 'weight_grams',
        'weight_bracket', 'cod_amount', 'delivery_fee', 'cod_fee', 'total', 'confidence', 'sample_size',
        'basis', 'inputs', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'weight_grams' => 'integer',
            'sample_size' => 'integer',
            'cod_amount' => 'decimal:4',
            'delivery_fee' => 'decimal:2',
            'cod_fee' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A fee estimate is a record of what was said at booking. It is never changed.'));
        static::deleting(fn () => throw new LogicException('A fee estimate is a record of what was said at booking. It is never deleted.'));
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(CourierRateCard::class, 'courier_rate_card_id');
    }
}
