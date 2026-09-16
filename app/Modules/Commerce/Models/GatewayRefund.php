<?php

namespace App\Modules\Commerce\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to send a customer's money back through SSLCommerz (slice P4).
 * See the migration.
 *
 * ⚠ Written only by the gateway refund actions. No screen edits it.
 */
class GatewayRefund extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        // ⚠ As on a payment: carries the ORDER's business, written from the
        // console as well as from a screen.
        'business_id',
        'gateway_payment_id',
        'sales_order_id',
        'refund_trans_id',
        'amount',
        'remarks',
        'status',
        'requested_by',
    ];

    protected $attributes = [
        'status' => 'requested',
    ];

    protected function casts(): array
    {
        return [
            'status' => GatewayRefundStatus::class,
            'amount' => 'decimal:4',
            'initiated_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class, 'gateway_payment_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customerRefund(): BelongsTo
    {
        return $this->belongsTo(CustomerRefund::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function amountMoney(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    /**
     * What an order has on its way back through SSLCommerz and not yet booked.
     *
     * ⚠ Unscoped by business on purpose, and narrowed by the order's own id: an
     * order's money must read the same whichever business context the reader is
     * in, and a scope that read zero would release the reservation silently.
     */
    public static function inFlightFor(SalesOrder $order): Money
    {
        return Money::ofTaka((string) (static::query()->withoutGlobalScopes()
            ->where('sales_order_id', $order->id)
            ->whereIn('status', array_map(fn (GatewayRefundStatus $status) => $status->value, GatewayRefundStatus::inFlight()))
            ->sum('amount') ?: '0'));
    }

    /**
     * What a payment has already given back, or is giving back.
     */
    public static function takenFrom(GatewayPayment $payment): Money
    {
        return Money::ofTaka((string) (static::query()->withoutGlobalScopes()
            ->where('gateway_payment_id', $payment->id)
            ->whereIn('status', array_map(fn (GatewayRefundStatus $status) => $status->value, GatewayRefundStatus::takingMoney()))
            ->sum('amount') ?: '0'));
    }
}
