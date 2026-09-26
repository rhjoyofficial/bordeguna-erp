<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The record that HQ cancelled an order it had placed (HQ docs/88 A point 3, docs/79 C.15).
 *
 * ⚠ The cancellation itself is `CancelSalesOrder`, the ERP's one rule (only an open order with nothing
 * delivered). This row keeps HQ's key, what it asked, and the reason, so a repeated request is answered
 * the same way and HQ can read back that its cancellation is the one that happened.
 *
 * ⛔ IMMUTABLE: every update and delete through the model is refused; only `CancelHqSalesOrder` writes it.
 */
class SalesOrderHqCancellation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'sales_order_id', 'idempotency_key', 'payload_digest', 'reason', 'hq_reference',
        'correlation_id', 'hq_token_id', 'requested_by',
    ];

    protected function casts(): array
    {
        return ['hq_reference' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An HQ cancellation is immutable.'));
        static::deleting(fn () => throw new LogicException('An HQ cancellation is never deleted: HQ reconciles against it.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
