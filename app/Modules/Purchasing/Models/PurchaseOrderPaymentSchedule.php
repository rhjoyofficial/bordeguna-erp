<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ One planned part payment on a purchase order (purchase redesign PR1):
 * "50% before shipment, the rest after receipt". A plan only: it moves no
 * money. Paying is an advance (before the bill) or a payment (after it).
 */
class PurchaseOrderPaymentSchedule extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'sort',
        'due_on',
        'amount_paisa',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'amount_paisa' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function money(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }
}
