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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One cost on a landed-cost document: what it was for, what happens to it
 * (the type's seed default, or an override with its reason), how it spreads
 * over the receipt, and who was or will be paid.
 *
 * ⚠ The payee is EITHER a supplier (the credit lands on Accounts Payable with
 * the supplier as party, so it shows on the supplier statement) OR a money
 * account (already paid; the credit lands on that till or bank). Never both,
 * never neither.
 */
class LandedCostLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'landed_cost_id',
        'sort',
        'cost_type',
        'treatment',
        'default_treatment',
        'override_reason',
        'allocation_method',
        'goods_receipt_line_id',
        'amount_paisa',
        'supplier_id',
        'money_account_id',
        // ⭐ Initial plan B1 (R49): a one-off payee's name on a cost paid from an account.
        'payee_name',
        'expense_account_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'cost_type' => LandedCostType::class,
            'treatment' => LandedCostTreatment::class,
            'default_treatment' => LandedCostTreatment::class,
            'allocation_method' => LandedCostAllocationMethod::class,
            'amount_paisa' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function targetReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
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

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_paisa);
    }

    public function isCapitalised(): bool
    {
        return $this->treatment === LandedCostTreatment::Capitalize;
    }

    /**
     * The default that applied when this line was saved.
     *
     * ⚠ 18 Oct 2026 (decision D-A): the defaults changed, and a line saved
     * under the 17 Oct table keeps the default it was saved under, so a posted
     * expensed freight line does not start to read as an unexplained override.
     */
    public function defaultTreatment(): LandedCostTreatment
    {
        return $this->default_treatment ?? $this->cost_type->defaultTreatment();
    }

    /** True when the treatment is not the default it was saved under (then a reason is required). */
    public function overridesDefault(): bool
    {
        return $this->treatment !== $this->defaultTreatment();
    }
}
