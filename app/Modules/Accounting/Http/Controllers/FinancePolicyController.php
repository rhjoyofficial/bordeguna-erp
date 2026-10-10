<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('accounting/finance-policy/index', [
            'policy' => $values,
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

        $data = $request->validate($rules, [
            'bad_debt_full_after_days.gte' => 'Full provision cannot come before the half provision.',
        ]);

        $attributes = collect($data)->except(array_map(self::takaKey(...), FinancePolicy::MONEY_FIELDS))->all();

        foreach (FinancePolicy::MONEY_FIELDS as $field) {
            $attributes[$field] = (int) round(((float) $data[self::takaKey($field)]) * 100);
        }

        $businessId = app(BusinessContext::class)->id();

        FinancePolicy::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $businessId],
            $attributes + ['updated_by' => $request->user()->id],
        );

        return back()->with('success', 'Finance policy saved.');
    }

    /** payment_second_approval_above_minor -> payment_second_approval_above */
    private static function takaKey(string $field): string
    {
        return substr($field, 0, -strlen('_minor'));
    }
}
