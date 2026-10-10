<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Every limit, cap and threshold the books obey, one row per business
 * (Finance & Accounts > Finance policy, 29 Sep 2026).
 *
 * ⚠ A business with no saved row still has a policy: {@see self::for()} returns
 * an unsaved instance carrying the migration's defaults, so a guard never has
 * to ask whether the Owner has visited the screen yet.
 */
class FinancePolicy extends Model
{
    use BelongsToBusiness, LogsActivity;

    public const MONEY_FIELDS = [
        'payment_second_approval_above_minor',
        'advance_refund_owner_above_minor',
        'cash_payment_limit_minor',
        'raw_material_cash_limit_minor',
        'petty_cash_float_minor',
        'capitalisation_threshold_minor',
        'staff_discount_cap_minor',
    ];

    protected $fillable = [
        'business_id',
        'refuse_negative_money',
        ...self::MONEY_FIELDS,
        'staff_purchase_cap_percent',
        'manager_discount_cap_percent',
        'staff_discount_cap_percent',
        'bad_debt_half_after_days',
        'bad_debt_full_after_days',
        'courier_settlement_alert_days',
        'updated_by',
    ];

    /**
     * ⚠ Mirrors the migration defaults IN THE MODEL: an unsaved policy is the
     * one every guard reads until the Owner saves a different one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'refuse_negative_money' => true,
        'payment_second_approval_above_minor' => 2_000_000,
        'advance_refund_owner_above_minor' => 2_000_000,
        'cash_payment_limit_minor' => 5_000_000,
        'raw_material_cash_limit_minor' => 50_000_000,
        'petty_cash_float_minor' => 1_000_000,
        'capitalisation_threshold_minor' => 1_000_000,
        'staff_purchase_cap_percent' => 10,
        'manager_discount_cap_percent' => 3,
        'staff_discount_cap_percent' => 1,
        'staff_discount_cap_minor' => 30_000,
        'bad_debt_half_after_days' => 180,
        'bad_debt_full_after_days' => 365,
        'courier_settlement_alert_days' => 7,
    ];

    protected function casts(): array
    {
        return [
            'refuse_negative_money' => 'boolean',
            'payment_second_approval_above_minor' => 'integer',
            'advance_refund_owner_above_minor' => 'integer',
            'cash_payment_limit_minor' => 'integer',
            'raw_material_cash_limit_minor' => 'integer',
            'petty_cash_float_minor' => 'integer',
            'capitalisation_threshold_minor' => 'integer',
            'staff_purchase_cap_percent' => 'integer',
            'manager_discount_cap_percent' => 'integer',
            'staff_discount_cap_percent' => 'integer',
            'staff_discount_cap_minor' => 'integer',
            'bad_debt_half_after_days' => 'integer',
            'bad_debt_full_after_days' => 'integer',
            'courier_settlement_alert_days' => 'integer',
        ];
    }

    public static function for(int $businessId): self
    {
        return self::withoutGlobalScopes()->where('business_id', $businessId)->first()
            ?? new self(['business_id' => $businessId]);
    }

    public function money(string $field): Money
    {
        return Money::ofMinor((int) $this->{$field});
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('finance_policy');
    }
}
