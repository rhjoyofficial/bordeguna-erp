<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\Reversible;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ A discount a supplier gave on goods (ChatGPT ruling P3, 18 Oct 2026).
 * SD-2026-27-0001.
 *
 *   trade        printed on the supplier's bill, recorded with the bill:
 *                the supplier's document number is the reference
 *   settlement   taken when paying, on the payment screen, with the evidence
 *                of the agreement and a reason (purchasing.discount.settle)
 *
 * Either way it is a reduction of what the goods COST (IAS 2 / IFRS for SMEs
 * 13.6), never income: Dr 2110 AP (supplier) / Cr 1150 for the units still on
 * hand (each batch's cost restated down) / Cr purchasing.discount_consumed for
 * the units already consumed or sold. ⚠ Never 4950 and never 5400 PPV.
 *
 * The bill's outstanding falls through the party adjustment it keeps
 * (`party_adjustment_id`), so every reader of adjustments sees it; reversing
 * it through the corrections process removes that adjustment, so the bill is
 * owed again, and puts the cost back on the batches.
 */
class SupplierDiscount extends Model
{
    use BelongsToBusiness, LogsActivity, Reversible;

    public const TRADE = 'trade';

    public const SETTLEMENT = 'settlement';

    public const KINDS = [
        self::TRADE => 'Trade discount',
        self::SETTLEMENT => 'Settlement discount',
    ];

    /** The party adjustment kind each discount kind keeps its allocation under. */
    public const ADJUSTMENT_KINDS = [
        self::TRADE => 'supplier_trade_discount',
        self::SETTLEMENT => 'supplier_settlement_discount',
    ];

    protected $fillable = [
        'business_id', 'number', 'kind', 'supplier_id', 'supplier_bill_id', 'supplier_payment_id', 'discount_on',
        'amount', 'basis', 'percent', 'reference', 'reason', 'on_hand_paisa', 'consumed_paisa',
        'party_adjustment_id', 'journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_on' => 'date:Y-m-d',
            'amount' => 'decimal:4',
            'percent' => 'decimal:4',
            'on_hand_paisa' => 'integer',
            'consumed_paisa' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'kind', 'discount_on', 'amount', 'reference', 'reason'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('supplier_discount');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(PartyAdjustment::class, 'party_adjustment_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierDiscountAllocation::class);
    }

    public function money(): Money
    {
        return Money::ofTaka((string) $this->amount);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
