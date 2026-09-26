<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The record that an HQ draft became a sales order (HQ docs/88 A point 3).
 *
 * ⭐ The link, the key HQ sent and what exactly it asked, kept BESIDE the draft and the order: the
 * draft stays immutable and the order stays an ordinary sales order in the ERP's approval inbox. One
 * draft makes at most one order and one order comes from at most one draft (unique indexes).
 *
 * ⛔ IMMUTABLE, like the draft: every update and delete through the model is refused, and a source test
 * holds that only `ConfirmHqSalesOrderDraft` writes the table.
 */
class SalesOrderHqConfirmation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'sales_order_draft_id', 'sales_order_id', 'idempotency_key', 'payload_digest', 'hq_reference',
        'correlation_id', 'hq_token_id', 'requested_by',
    ];

    protected function casts(): array
    {
        return ['hq_reference' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An HQ confirmation is immutable: it records how one draft became one order.'));
        static::deleting(fn () => throw new LogicException('An HQ confirmation is never deleted: HQ reconciles against it.'));
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(SalesOrderDraft::class, 'sales_order_draft_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
