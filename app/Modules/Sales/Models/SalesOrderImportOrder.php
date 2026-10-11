<?php

namespace App\Modules\Sales\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one shop order in an import became: created, skipped (already imported)
 * or failed. Keeps the brand and the shop's channel, so the brand can reach the
 * order the day `sales_orders.brand_id` exists, even for orders imported before.
 */
class SalesOrderImportOrder extends Model
{
    use BelongsToBusiness;

    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = [
        'business_id', 'sales_order_import_id', 'order_ref', 'sales_order_id', 'brand_id', 'brand_name',
        'source_channel', 'outcome', 'message',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(SalesOrderImport::class, 'sales_order_import_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
