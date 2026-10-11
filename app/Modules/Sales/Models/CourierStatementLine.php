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

    /** Matched on the courier's consignment id. */
    public const BY_CONSIGNMENT = 'consignment';

    /** Matched on the merchant order id: our challan, order or shop reference. */
    public const BY_MERCHANT_REFERENCE = 'merchant_reference';

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
        'net_fee',
        'promo_discount',
        'cod_fee',
        'additional_charge',
        'final_fee',
        'compensation',
        'adjustment',
        'payout',
        'hub_weight_grams',
        'delivery_status',
        'delivered_on',
        'consignment_id',
        'match_method',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'collectable_amount' => 'decimal:4',
            'collected_amount' => 'decimal:4',
            'delivery_fee' => 'decimal:4',
            'discount' => 'decimal:4',
            'net_fee' => 'decimal:4',
            'promo_discount' => 'decimal:4',
            'cod_fee' => 'decimal:4',
            'additional_charge' => 'decimal:4',
            'final_fee' => 'decimal:4',
            'payout' => 'decimal:4',
            'compensation' => 'decimal:4',
            'adjustment' => 'decimal:4',
            'hub_weight_grams' => 'integer',
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

    /** The fee net of the courier's discount, before COD and extra charges. */
    public function netFee(): Money
    {
        return Money::ofMinor(Money::ofTaka((string) $this->delivery_fee)->minor - Money::ofTaka((string) $this->discount)->minor);
    }

    public function additionalCharge(): Money
    {
        return Money::ofTaka((string) $this->additional_charge);
    }

    /** What the courier paid for a parcel it lost or damaged. */
    public function compensationAmount(): Money
    {
        return Money::ofTaka((string) ($this->compensation ?? '0'));
    }

    /** The courier's own correction, signed: positive is money to us. */
    public function adjustmentAmount(): Money
    {
        return Money::ofTaka((string) ($this->adjustment ?? '0'));
    }

    public function payoutAmount(): Money
    {
        return Money::ofTaka((string) $this->payout);
    }
}
