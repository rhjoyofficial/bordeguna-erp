<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Finance & Accounts > Finance policy: every limit the books obey, editable by
 * whoever holds `accounting.policy.manage` (the Owner by default).
 *
 * ⚠ Amounts cross the wire in TAKA and are stored in PAISA, the same split
 * every other money form uses.
 */
class FinancePolicyController extends Controller
{
    public function edit(): Response
    {
        $policy = FinancePolicy::for(app(BusinessContext::class)->id());

        $values = [
            'refuse_negative_money' => $policy->refuse_negative_money,
            'staff_purchase_cap_percent' => $policy->staff_purchase_cap_percent,
            'manager_discount_cap_percent' => $policy->manager_discount_cap_percent,
            'staff_discount_cap_percent' => $policy->staff_discount_cap_percent,
            'bad_debt_half_after_days' => $policy->bad_debt_half_after_days,
            'bad_debt_full_after_days' => $policy->bad_debt_full_after_days,
            'courier_settlement_alert_days' => $policy->courier_settlement_alert_days,
        ];

        foreach (FinancePolicy::MONEY_FIELDS as $field) {
            $values[self::takaKey($field)] = $policy->money($field)->toTaka();
        }

        // ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026).
        $values['purchasing_approval_at_or_above'] = $policy->money('purchasing_approval_at_or_above_minor')->toTaka();
        $values['purchasing_approver_roles'] = $policy->purchasingApproverRoles();
        $values['purchasing_price_tolerance_percent'] = $policy->purchasing_price_tolerance_percent;
        $values['purchasing_unusual_discount_percent'] = $policy->purchasing_unusual_discount_percent;

        foreach (array_keys(FinancePolicy::PURCHASING_EXCEPTION_SWITCHES) as $switch) {
            $values[$switch] = (bool) $policy->{$switch};
        }

        return Inertia::render('accounting/finance-policy/index', [
            'policy' => $values,
            'purchasing_switches' => collect(FinancePolicy::PURCHASING_EXCEPTION_SWITCHES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'roles' => $this->roleNames(),
            'saved' => $policy->exists,
            'updated_at' => $policy->updated_at?->toDateTimeString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'refuse_negative_money' => ['required', 'boolean'],
            'staff_purchase_cap_percent' => ['required', 'integer', 'between:0,100'],
            'manager_discount_cap_percent' => ['required', 'integer', 'between:0,100'],
            'staff_discount_cap_percent' => ['required', 'integer', 'between:0,100'],
            'bad_debt_half_after_days' => ['required', 'integer', 'between:1,3650'],
            'bad_debt_full_after_days' => ['required', 'integer', 'between:1,3650', 'gte:bad_debt_half_after_days'],
            'courier_settlement_alert_days' => ['required', 'integer', 'between:1,90'],
        ];

        foreach (FinancePolicy::MONEY_FIELDS as $field) {
            $rules[self::takaKey($field)] = ['required', 'numeric', 'min:0', 'max:100000000'];
        }

        // ⭐ Purchasing approvals (ruling P7). `sometimes`: a form from before
        // these fields existed still saves the rest unchanged.
        $rules['purchasing_approval_at_or_above'] = ['sometimes', 'required', 'numeric', 'min:0', 'max:100000000'];
        $rules['purchasing_approver_roles'] = ['sometimes', 'required', 'array', 'min:1'];
        $rules['purchasing_approver_roles.*'] = ['string', 'in:'.implode(',', $this->roleNames())];
        $rules['purchasing_price_tolerance_percent'] = ['sometimes', 'required', 'integer', 'between:0,100'];
        $rules['purchasing_unusual_discount_percent'] = ['sometimes', 'required', 'integer', 'between:0,100'];

        foreach (array_keys(FinancePolicy::PURCHASING_EXCEPTION_SWITCHES) as $switch) {
            $rules[$switch] = ['sometimes', 'required', 'boolean'];
        }

        $data = $request->validate($rules, [
            'purchasing_approver_roles.min' => 'Choose at least one role that may approve purchasing documents.',
            'bad_debt_full_after_days.gte' => 'Full provision cannot come before the half provision.',
        ]);

        $attributes = collect($data)->except([...array_map(self::takaKey(...), FinancePolicy::MONEY_FIELDS), 'purchasing_approval_at_or_above'])->all();

        if (array_key_exists('purchasing_approval_at_or_above', $data)) {
            $attributes['purchasing_approval_at_or_above_minor'] = (int) round(((float) $data['purchasing_approval_at_or_above']) * 100);
        }

        if (array_key_exists('purchasing_approver_roles', $data)) {
            $attributes['purchasing_approver_roles'] = array_values(array_unique($data['purchasing_approver_roles']));
        }

        foreach (FinancePolicy::MONEY_FIELDS as $field) {
            $attributes[$field] = (int) round(((float) $data[self::takaKey($field)]) * 100);
        }

        $businessId = app(BusinessContext::class)->id();

        FinancePolicy::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $businessId],
            $attributes + ['updated_by' => $request->user()->id],
        );

        // The approvals inbox and the emails reach the roles saved here.
        app(PurchasingApprovalGate::class)->syncWorkflow($businessId);

        return back()->with('success', 'Finance policy saved.');
    }

    /**
     * The roles of this business, for the purchasing approver choice.
     *
     * @return list<string>
     */
    private function roleNames(): array
    {
        $names = Role::query()->where('business_id', app(BusinessContext::class)->id())->where('guard_name', 'web')
            ->orderBy('name')->pluck('name')->all();

        return array_values(array_unique([...FinancePolicy::DEFAULT_PURCHASING_APPROVER_ROLES, ...$names]));
    }

    /** payment_second_approval_above_minor -> payment_second_approval_above */
    private static function takaKey(string $field): string
    {
        return substr($field, 0, -strlen('_minor'));
    }
}
