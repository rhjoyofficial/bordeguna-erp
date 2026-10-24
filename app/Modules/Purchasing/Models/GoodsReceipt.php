<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Purchasing\Models\Concerns\Reversible;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): Reversible. A receipt is never edited
 * or deleted once its goods are in stock: Fix batch details changes label
 * data only, Reverse takes untouched goods back out, Correct reverses it and
 * records the right receipt (GoodsReceiptCorrector). The wrong one stays,
 * marked, and `scopeLive()` is what every count and every billable figure reads.
 */
class GoodsReceipt extends Model
{
    use BelongsToBusiness, HasAttachments, HasFactory, Reversible;

    /** ⭐ Initial plan B1 (R58): the challan photo, a courier receipt, a labour slip. */
    public const ATTACHMENTS_COLLECTION = 'receipt-documents';

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'received_by',
        'received_at',
        // The supplier's delivery note number, as printed on their paper.
        'supplier_challan_number',
        'backdate_reason',
        'note',
        'details_fixed_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'details_fixed_at' => 'datetime',
        ];
    }

    /** "Receipt #12", as the corrections process and the History name it. */
    public function label(): string
    {
        return "Receipt #{$this->id}";
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** Costs that arrived beside or after this delivery (Phase 1, 17 Oct 2026). */
    public function landedCosts(): HasMany
    {
        return $this->hasMany(LandedCost::class);
    }
}
