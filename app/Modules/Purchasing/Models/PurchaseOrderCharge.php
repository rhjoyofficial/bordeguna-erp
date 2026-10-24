<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ A cost on the purchase order beside the goods (purchase redesign PR1, 18
 * Oct 2026): freight, carriage, labour, insurance, duty, other. Tally's
 * "additional cost of purchase", typed once on the order.
 *
 * ⚠⚠ IT POSTS NOTHING. On each receipt it becomes (its share of) a landed
 * cost through the ordinary landed-cost engine (RaiseOrderCharges), and
 * `landed_paisa` grows by that share, so a second delivery takes only what is
 * left and the same money never reaches the stock twice.
 *
 * ⚠ The payee is EITHER a supplier (this order's or another, e.g. the
 * transporter) OR a money account, exactly as a landed-cost line.
 */
class PurchaseOrderCharge extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'purchase_order_id',
        'sort',
        'cost_type',
        'description',
        'amount_paisa',
        'allocation_method',
        'purchase_order_line_id',
        'supplier_id',
        'money_account_id',
        // ⭐ Initial plan B1 (R49): a one-off payee's name on a cost paid from an account.
        'payee_name',
        'treatment',
        'override_reason',
        'expense_account_id',
        'landed_paisa',
    ];

    protected function casts(): array
    {
        return [
            'cost_type' => LandedCostType::class,
            'allocation_method' => LandedCostAllocationMethod::class,
            'treatment' => LandedCostTreatment::class,
            'amount_paisa' => 'integer',
            'landed_paisa' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /** The treatment that applies: the typed override, else the type's default. */
    public function effectiveTreatment(): LandedCostTreatment
    {
        return $this->treatment ?? $this->cost_type->defaultTreatment();
    }

    public function money(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }

    /** What no receipt has taken yet. */
    public function remaining(): Money
    {
        return Money::ofMinor(max(0, (int) $this->amount_paisa - (int) $this->landed_paisa));
    }
}
