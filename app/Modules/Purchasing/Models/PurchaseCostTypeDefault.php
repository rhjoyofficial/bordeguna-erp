<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Support\CostTypeDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ⭐ Joy's answer 3 (25 Oct 2026): the Owner's default for one purchase cost
 * type in one business: into the stock cost, or an expense under the named
 * head. Read through App\Modules\Purchasing\Support\CostTypeDefaults, which
 * is what LandedCostType::defaultTreatment() answers from.
 */
class PurchaseCostTypeDefault extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'cost_type',
        'treatment',
        'expense_account_id',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // The request-scoped cache follows every write (CostTypeDefaults).
        static::saved(fn () => CostTypeDefaults::forget());
        static::deleted(fn () => CostTypeDefaults::forget());
    }

    protected function casts(): array
    {
        return [
            'cost_type' => LandedCostType::class,
            'treatment' => LandedCostTreatment::class,
        ];
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('purchase_cost_type_default');
    }
}
