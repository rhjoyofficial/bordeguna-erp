<?php

namespace App\Modules\Sales\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One parcel on a courier's payout statement, as the courier reported it.
 */
class CourierStatementLine extends Model
{
    use BelongsToBusiness;

    /** A parcel the ERP does not know, which a person says belongs to this business. */
    public const MINE = 'mine';

    /** A parcel the ERP does not know, which a person says belongs elsewhere. */
    public const NOT_MINE = 'not_mine';

    protected $fillable = [
        'business_id',
        'courier_statement_id',
        'row_number',
        'consignment_number',
        'merchant_reference',
        'booked_at',
        'collectable_amount',
        'collected_amount',
        'delivery_fee',
        'discount',
        'cod_fee',
        'additional_charge',
        'final_fee',
        'payout',
        'consignment_id',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'collectable_amount' => 'decimal:4',
            'collected_amount' => 'decimal:4',
            'delivery_fee' => 'decimal:4',
            'discount' => 'decimal:4',
            'cod_fee' => 'decimal:4',
            'additional_charge' => 'decimal:4',
            'final_fee' => 'decimal:4',
            'payout' => 'decimal:4',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CourierStatement::class, 'courier_statement_id');
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function claim(): HasOne
    {
        return $this->hasOne(CourierParcelClaim::class);
    }

    public function collected(): Money
    {
        return Money::ofTaka((string) $this->collected_amount);
    }

    /**
     * What the courier charged to carry it: its list fee less its own discount,
     * plus any extra charge. The COD fee is separate.
     */
    public function deliveryCost(): Money
    {
        return Money::ofMinor(
            Money::ofTaka((string) $this->delivery_fee)->minor
                - Money::ofTaka((string) $this->discount)->minor
                + Money::ofTaka((string) $this->additional_charge)->minor
        );
    }

    public function codFee(): Money
    {
        return Money::ofTaka((string) $this->cod_fee);
    }

    public function payoutAmount(): Money
    {
        return Money::ofTaka((string) $this->payout);
    }
}
