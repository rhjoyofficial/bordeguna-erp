<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One word from a courier about one parcel, from its webhook, our pull, or
 * our own booking and cancelling. Unique (consignment, event, occurred_at).
 *
 * ⚠ `details` is a whitelist: amounts, attempt, reason, invoice id, weight.
 * The webhook body itself is never kept, only its hash.
 */
class CourierShipmentEvent extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    public const WEBHOOK = 'webhook';

    public const POLL = 'poll';

    public const BOOKING = 'booking';

    protected $fillable = [
        'business_id', 'consignment_id', 'source', 'event', 'raw_status', 'canonical_status',
        'occurred_at', 'payload_hash', 'applied', 'details',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'applied' => 'boolean',
            'details' => 'array',
            'canonical_status' => CourierStatus::class,
        ];
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }
}
