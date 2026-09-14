<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay an order online (SSLCommerz slice P2b). See the migration.
 *
 * ⚠ Written only by the gateway actions. No screen edits it.
 */
class GatewayPayment extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        // ⚠ Fillable here, unlike most models: an attempt is written from a
        // storefront request and from the console, and must carry the ORDER's
        // business, never whatever context happens to be ambient.
        'business_id',
        'store_id',
        'sales_order_id',
        'provider',
        'tran_id',
        'attempt',
        'amount',
        'status',
        'is_live',
    ];

    protected $attributes = [
        'status' => 'initiated',
        'is_live' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => GatewayPaymentStatus::class,
            'amount' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'store_amount' => 'decimal:4',
            'is_live' => 'boolean',
            'risk_level' => 'integer',
            'attempt' => 'integer',
            'proven_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function amountMoney(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function amountPaidMoney(): ?Money
    {
        return $this->amount_paid === null ? null : Money::ofTaka((string) $this->amount_paid);
    }

    /**
     * What the gateway kept: the gross the customer paid less what it settles.
     * Zero when it reported no store amount, or one that makes no sense.
     */
    public function fee(): Money
    {
        $paid = $this->amountPaidMoney();

        if ($paid === null || $this->store_amount === null) {
            return Money::zero();
        }

        $fee = $paid->minor - Money::ofTaka((string) $this->store_amount)->minor;

        return Money::ofMinor($fee > 0 && $fee <= $paid->minor ? $fee : 0);
    }

    public function isRisky(): bool
    {
        return (int) $this->risk_level === 1;
    }
}
