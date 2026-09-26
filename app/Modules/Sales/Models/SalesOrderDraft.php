<?php

namespace App\Modules\Sales\Models;

use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A draft sales order HQ left in the ERP (HQ docs/83 C.3, docs/79): an order someone proposed and a
 * person approved on the HQ side, priced and checked here, and NOTHING ELSE.
 *
 * ⚠⚠ NOT A SALES ORDER, AND IT NEVER BECOMES ONE ON ITS OWN. It has no order number, no approval
 * request, no customer it created, no stock, no journal and no notification. `CreateSalesOrder` does
 * all of those, which is exactly why a draft does not go through it. An order is made FROM a draft only
 * by `ConfirmHqSalesOrderDraft` (HQ docs/88 A point 3), and the link is its own row
 * (`SalesOrderHqConfirmation`); there is no method here, and no route anywhere, that moves a draft out
 * of `draft`.
 *
 * ⛔ IMMUTABLE. A draft is the ERP's record of what was agreed at one moment, and HQ reconciles
 * against it; a row that could be edited afterwards would be a record that proves nothing. Every
 * update and every delete through the model is refused, and a source test holds that nothing under
 * `app/` writes the table except `CreateSalesOrderDraft`.
 */
class SalesOrderDraft extends Model
{
    use BelongsToBusiness;

    public const STATUS = 'draft';

    /** The version stamped on every HQ body about drafts. */
    public const CONTRACT = 'hq-sales-order-drafts.v1';

    protected $fillable = [
        'business_id', 'store_id', 'status', 'idempotency_key', 'payload_digest', 'hq_proposal_fingerprint',
        'hq_reference', 'customer_id', 'customer_resolution', 'recipient_name', 'recipient_phone',
        'recipient_phone_normalized', 'district', 'thana', 'area', 'postcode', 'address_line', 'landmark',
        'payment_method', 'goods_total', 'delivery_charge', 'order_discount', 'agreed_total', 'priced_at',
        'correlation_id', 'hq_token_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hq_reference' => 'array',
            'priced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $draft) {
            // A draft is written as a draft or not at all.
            if (($draft->status ?? self::STATUS) !== self::STATUS) {
                throw new LogicException('A sales order draft can only be written as a draft.');
            }
            $draft->status = self::STATUS;
        });

        static::updating(fn () => throw new LogicException('A sales order draft is immutable: it records what was agreed at one moment.'));
        static::deleting(fn () => throw new LogicException('A sales order draft is never deleted: HQ reconciles against it.'));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderDraftLine::class)->orderBy('id');
    }
}
