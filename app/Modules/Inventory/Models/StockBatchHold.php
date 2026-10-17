<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hold or one release of a batch, with its reason (Traceability M2,
 * 17 Oct 2026). Written once, never edited: the batch's own columns say
 * whether it is on hold now, this says who put it there, who let it go, and
 * why.
 */
class StockBatchHold extends Model
{
    use BelongsToBusiness;

    public const HOLD = 'hold';

    public const RELEASE = 'release';

    protected $fillable = [
        'business_id',
        'stock_batch_id',
        'action',
        'reason',
        'user_id',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
