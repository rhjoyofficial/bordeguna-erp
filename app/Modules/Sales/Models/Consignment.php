<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\DoorResolution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A challan handed to a courier, and what the courier reported back.
 *
 * ⭐ While it is BOOKED the challan cannot be invoiced or returned by hand: its
 * invoice is raised when the courier confirms delivery (Joy, 13 September), and
 * a refused parcel comes back through the outcome, not the returns screen.
 */
class Consignment extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'delivery_id',
        'money_account_id',
        'consignment_number',
        'cod_amount',
        'status',
        'outcome_on',
        'collected_amount',
        'outcome_note',
        'sales_invoice_id',
        'customer_receipt_id',
        'sales_return_id',
        'created_by',
        'outcome_recorded_by',
        // ⭐ Courier hub slice 3: what the courier was told the parcel weighs,
        // and what its lines weigh by the product weights at booking.
        'declared_weight_auto_grams',
        'declared_weight_grams',
        // ⭐ Courier hub slice 4: booked through the courier's API, and what
        // the COURIER says since. None of it is money (ruling 1).
        'courier_account_id',
        'provider_consignment_id',
        'merchant_order_ref',
        'booked_via',
        'provider_fee_quoted',
        'provider_cod_fee_quoted',
        'courier_status',
        'courier_status_raw',
        'courier_status_at',
        'status_synced_at',
        'courier_city_id',
        'courier_zone_id',
        'courier_area_id',
        'hub_weight_grams',
        'courier_collected_amount',
        'courier_invoice_ref',
        // ⭐ Courier hub slice 8: a difference at the door and how it was
        // settled, and a courier answer dated into a closed month.
        'door_resolution',
        'door_difference',
        'door_reason',
        'party_adjustment_id',
        'posted_on',
        'cutoff_note',
    ];

    public const BOOKED_VIA_API = 'api';

    public const BOOKED_VIA_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'status' => ConsignmentStatus::class,
            'cod_amount' => 'decimal:4',
            'collected_amount' => 'decimal:4',
            'outcome_on' => 'date:Y-m-d',
            'declared_weight_auto_grams' => 'integer',
            'declared_weight_grams' => 'integer',
            'courier_status' => CourierStatus::class,
            'courier_status_at' => 'datetime',
            'status_synced_at' => 'datetime',
            'provider_fee_quoted' => 'decimal:4',
            'provider_cod_fee_quoted' => 'decimal:4',
            'courier_collected_amount' => 'decimal:4',
            'hub_weight_grams' => 'integer',
            'door_resolution' => DoorResolution::class,
            'door_difference' => 'decimal:4',
            'posted_on' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['consignment_number', 'money_account_id', 'cod_amount', 'status', 'outcome_on', 'posted_on', 'collected_amount', 'door_resolution', 'door_difference', 'door_reason', 'declared_weight_grams', 'courier_status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * The courier, as the money account its cash on delivery sits in.
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    /** The courier account it was booked through (null when typed by hand). */
    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(CourierShipmentEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function isBookedViaApi(): bool
    {
        return $this->booked_via === self::BOOKED_VIA_API;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    /** The discount or rounding written off against its invoice (slice 8). */
    public function partyAdjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class);
    }

    /** The claim on the courier for a parcel it lost or damaged (slice 8). */
    public function lossClaim(): HasOne
    {
        return $this->hasOne(CourierLossClaim::class);
    }

    /** Door discounts waiting for, or decided by, the Owner (slice 8). */
    public function doorDiscountRequests(): HasMany
    {
        return $this->hasMany(CourierDoorDiscountRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The payout statement line that settled this parcel. A line carries its
     * consignment only once its statement is posted.
     */
    public function statementLine(): HasOne
    {
        return $this->hasOne(CourierStatementLine::class);
    }

    public function isBooked(): bool
    {
        return $this->status === ConsignmentStatus::Booked;
    }

    public function codAmount(): Money
    {
        return Money::ofTaka((string) $this->cod_amount);
    }

    public function collectedAmount(): ?Money
    {
        return $this->collected_amount === null ? null : Money::ofTaka((string) $this->collected_amount);
    }
}
