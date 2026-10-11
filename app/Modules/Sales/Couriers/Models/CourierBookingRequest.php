<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to book a challan with a courier (ruling 13), written BEFORE the
 * request leaves, so the ERP always knows it may have booked something.
 *
 * - requested: written, the request is on its way (or the process died).
 * - succeeded: the courier gave an id; the consignment exists.
 * - failed: the courier refused, or a person confirmed nothing was booked.
 *   Booking may be tried again (a new row, the same merchant_order_id).
 * - unknown: no clear answer. ⚠ Nobody books this challan again until it is
 *   settled: CarryBee by looking our reference up, Pathao by a person.
 *
 * ⚠ `response_summary` holds ids, amounts and the courier's message with
 * phone numbers taken out. Never a name, a phone or an address.
 */
class CourierBookingRequest extends Model
{
    use BelongsToBusiness;

    public const REQUESTED = 'requested';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    /** A `requested` row older than this lost its process: treat as unknown. */
    public const STALE_SECONDS = 120;

    protected $fillable = [
        'business_id', 'delivery_id', 'courier_account_id', 'merchant_order_id', 'request_hash', 'status',
        'response_summary', 'error', 'provider_consignment_id', 'consignment_id', 'created_by',
        'resolved_by', 'resolved_at', 'resolution',
    ];

    protected function casts(): array
    {
        return ['response_summary' => 'array', 'resolved_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class, 'courier_account_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still open: nobody may book this challan again while one exists. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::UNKNOWN], true);
    }

    /**
     * Unknown, or requested so long ago that its request cannot still be
     * running: a person (or the CarryBee lookup) has to settle it.
     */
    public function needsDecision(): bool
    {
        return $this->status === self::UNKNOWN
            || ($this->status === self::REQUESTED && $this->created_at !== null && $this->created_at->lt(now()->subSeconds(self::STALE_SECONDS)));
    }
}
