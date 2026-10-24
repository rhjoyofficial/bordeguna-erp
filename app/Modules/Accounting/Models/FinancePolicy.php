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
    ];

    protected $fillable = [
        'business_id',
        'refuse_negative_money',
        ...self::MONEY_FIELDS,
        // ⚠ 25 Oct 2026 (Joy, Q12 = A): the staff Tk ceiling is no longer read
        // (DiscountCap is percent only); kept fillable so old rows still load.
        'staff_discount_cap_minor',
        'staff_purchase_cap_percent',
        'manager_discount_cap_percent',
        'staff_discount_cap_percent',
        'bad_debt_half_after_days',
        'bad_debt_full_after_days',
        'courier_settlement_alert_days',
        // ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026).
        ...self::PURCHASING_APPROVAL_FIELDS,
        'updated_by',
    ];

    /**
     * ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026): the limit AT OR
     * ABOVE which a bill, payment, advance or refund needs a second person,
     * who may approve, and one switch per exception (ON, except new supplier and non-PO bill, OFF by
     * Joy's decision of 18 Oct 2026). Read by
     * App\Modules\Purchasing\Support\PurchasingApprovalGate.
     */
    public const PURCHASING_APPROVAL_FIELDS = [
        'purchasing_approval_at_or_above_minor',
        'purchasing_approver_roles',
        'purchasing_price_tolerance_percent',
        'purchasing_unusual_discount_percent',
        'purchasing_approve_non_po_bill',
        'purchasing_approve_price_mismatch',
        'purchasing_approve_unusual_discount',
        'purchasing_approve_advance_over_application',
        'purchasing_approve_new_supplier',
        'purchasing_approve_bank_change',
    ];

    /** The exception switches, each with the words the policy screen shows. */
    public const PURCHASING_EXCEPTION_SWITCHES = [
        'purchasing_approve_non_po_bill' => 'A bill with lines that are not on a purchase order',
        'purchasing_approve_price_mismatch' => 'A bill price above the order price by more than the tolerance',
        'purchasing_approve_unusual_discount' => 'A discount above the usual percentage',
        'purchasing_approve_advance_over_application' => 'Paying more than the bills owe, or advances above the order value',
        'purchasing_approve_new_supplier' => "A new supplier's first bill or first payment",
        'purchasing_approve_bank_change' => "The first payment after a supplier's bank or wallet details changed",
    ];

    public const DEFAULT_PURCHASING_APPROVER_ROLES = ['Owner', 'Manager'];

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
        // ⭐ 25 Oct 2026 (Joy, Q12 = A): per line, Manager 5%, staff 2%.
        'manager_discount_cap_percent' => 5,
        'staff_discount_cap_percent' => 2,
        'staff_discount_cap_minor' => 30_000,
        'bad_debt_half_after_days' => 180,
        'bad_debt_full_after_days' => 365,
        'courier_settlement_alert_days' => 7,
        'purchasing_approval_at_or_above_minor' => 20_000_000,
        'purchasing_price_tolerance_percent' => 2,
        'purchasing_unusual_discount_percent' => 10,
        // Joy, 18 Oct 2026: these two start OFF; the rest ON.
        'purchasing_approve_non_po_bill' => false,
        'purchasing_approve_price_mismatch' => true,
        'purchasing_approve_unusual_discount' => true,
        'purchasing_approve_advance_over_application' => true,
        'purchasing_approve_new_supplier' => false,
        'purchasing_approve_bank_change' => true,
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
            'purchasing_approval_at_or_above_minor' => 'integer',
            'purchasing_approver_roles' => 'array',
            'purchasing_price_tolerance_percent' => 'integer',
            'purchasing_unusual_discount_percent' => 'integer',
            'purchasing_approve_non_po_bill' => 'boolean',
            'purchasing_approve_price_mismatch' => 'boolean',
            'purchasing_approve_unusual_discount' => 'boolean',
            'purchasing_approve_advance_over_application' => 'boolean',
            'purchasing_approve_new_supplier' => 'boolean',
            'purchasing_approve_bank_change' => 'boolean',
        ];
    }

    public static function for(int $businessId): self
    {
        return self::withoutGlobalScopes()->where('business_id', $businessId)->first()
            ?? new self(['business_id' => $businessId]);
    }

    /**
     * Who may give the purchasing second approval: the saved roles, or Owner
     * and Manager while none are saved.
     *
     * @return list<string>
     */
    public function purchasingApproverRoles(): array
    {
        $roles = array_values(array_filter((array) ($this->purchasing_approver_roles ?? []), fn ($role) => is_string($role) && $role !== ''));

        return $roles === [] ? self::DEFAULT_PURCHASING_APPROVER_ROLES : $roles;
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
