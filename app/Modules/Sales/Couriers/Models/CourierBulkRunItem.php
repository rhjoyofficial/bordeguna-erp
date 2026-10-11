<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order in a bulk booking, and its answer: booked (with the courier's
 * number and quoted fee), refused (why), unknown (settle it on the order), or
 * fix address (the courier has no place for it yet; no challan was made).
 */
class CourierBulkRunItem extends Model
{
    use BelongsToBusiness;

    public const PENDING = 'pending';

    public const WORKING = 'working';

    public const BOOKED = 'booked';

    public const REFUSED = 'refused';

    public const UNKNOWN = 'unknown';

    public const FIX_ADDRESS = 'fix_address';

    /** Not answered yet: the order may not join another run. */
    public const OPEN = [self::PENDING, self::WORKING];

    protected $fillable = [
        'business_id', 'courier_bulk_run_id', 'sales_order_id', 'delivery_id', 'courier_account_id',
        'courier_booking_request_id', 'consignment_id', 'status', 'consignment_number', 'fee_quoted', 'cod_amount', 'message',
    ];

    protected function casts(): array
    {
        return ['cod_amount' => 'integer', 'fee_quoted' => 'decimal:2'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CourierBulkRun::class, 'courier_bulk_run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }
}
