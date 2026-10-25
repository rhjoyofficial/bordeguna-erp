<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\PurchaseCostTypeDefault;
use App\Modules\Purchasing\Support\CostTypeDefaults;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
            // ⭐ Joy's answer 3 (25 Oct 2026): each purchase cost type's default, editable in a popup per row.
            'cost_types' => $this->costTypes(),
            'expense_accounts' => $this->expenseAccounts(),
        ]);
    }

    /**
     * ⭐ Joy's answer 3 (25 Oct 2026): one purchase cost type's default, stock
     * cost or expense (with the head), per business. Every purchase screen
     * reads it from the next request on; documents already saved keep the
     * default they were saved under.
     */
    public function updateCostType(Request $request, string $type): RedirectResponse
    {
        $costType = LandedCostType::tryFrom($type);

        abort_if($costType === null, 404);

        $data = $request->validate([
            'treatment' => ['required', Rule::enum(LandedCostTreatment::class)],
            'expense_account_id' => ['nullable', 'integer'],
        ]);

        $treatment = LandedCostTreatment::from($data['treatment']);
        $accountId = $treatment === LandedCostTreatment::Expense && ! empty($data['expense_account_id']) ? (int) $data['expense_account_id'] : null;

        if ($treatment === LandedCostTreatment::Expense) {
            $account = $accountId === null ? null : Account::query()->find($accountId);

            if ($accountId !== null && ($account === null || $account->type !== AccountType::Expense || ! $account->is_postable || ! $account->is_active
                || in_array($account->id, VoucherAccounts::refusedIds(Carbon::today()), true))) {
                return back()->withErrors(['expense_account_id' => 'Pick a postable expense head.']);
            }

            // A type with no mapped head must name one, or every line of it would have to.
            if ($account === null && $costType->expenseMappingKey() === null) {
                return back()->withErrors(['expense_account_id' => 'Name the expense head '.$costType->label().' is posted to.']);
            }
        }

        $businessId = app(BusinessContext::class)->id();

        PurchaseCostTypeDefault::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $businessId, 'cost_type' => $costType->value],
            ['treatment' => $treatment->value, 'expense_account_id' => $accountId, 'updated_by' => $request->user()->id],
        );
        CostTypeDefaults::forget();

        return back()->with('success', $costType->label().' is now '.strtolower($treatment->label()).' by default.');
    }

    /** @return list<array<string, mixed>> */
    private function costTypes(): array
    {
        $today = Carbon::today();
        $defaults = CostTypeDefaults::all();
        $rows = [];

        foreach (LandedCostType::cases() as $type) {
            $default = $defaults[$type->value];
            $own = $default['expense_account_id'] === null ? null : Account::query()->find($default['expense_account_id']);
            $mapped = ($key = $type->expenseMappingKey()) === null ? null : AccountMapping::forKeyOn($key, $today)?->account;

            $rows[] = [
                'value' => $type->value,
                'label' => $type->label(),
                'treatment' => $default['treatment']->value,
                'expense_account_id' => $own?->id,
                'expense_account_label' => $own?->label(),
                // The head the engine uses when the Owner names none: the type's mapped head, or none (the line must name it).
                'mapped_head_label' => $mapped?->label(),
            ];
        }

        return $rows;
    }

    /** @return list<array{id: int, label: string}> */
    private function expenseAccounts(): array
    {
        return Account::query()
            ->where('type', AccountType::Expense)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
            ->all();
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
