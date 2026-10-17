<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A zero-charge replacement of a defective unit on a fulfilled staff purchase
 * (office order: a defect is exchanged; ruling D7). Linked to the original
 * request and line; no new invoice, no quota used; the replacement unit leaves
 * stock at its batch cost as a loss and the defective unit is written off,
 * never put back on the shelf.
 */
class StaffPurchaseReplacement extends Model
{
    use BelongsToBusiness;

    public const DISPOSITION_WRITE_OFF = 'write_off';

    protected $fillable = [
        'business_id',
        'request_id',
        'line_id',
        'product_id',
        'quantity',
        'unit_cost',
        'reason',
        'evidence_note',
        'defective_disposition',
        'stock_movement_id',
        'journal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffPurchaseRequest::class, 'request_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StaffPurchaseLine::class, 'line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
