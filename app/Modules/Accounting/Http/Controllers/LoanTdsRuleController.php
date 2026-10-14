<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Models\LoanTdsRule;
use App\Modules\Accounting\Support\Loans\LoanMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Accounting > Loans > TDS on loan interest (loans plan v2, phase 2; ChatGPT
 * confirmation, point 6): the effective-dated withholding rule on interest the
 * business pays. Read with the register; changed by whoever holds the finance
 * policy key (the Owner), because it changes what every repayment withholds.
 *
 * ⚠ Switching it ON asks for who confirmed it and on what legal basis: the
 * accountant's confirmation that the business is a "specified person".
 */
class LoanTdsRuleController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('accounting/loans/tds-rules', [
            'rules' => LoanTdsRule::query()->orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn (LoanTdsRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'payee_kinds' => $rule->payee_kinds ?? [],
                'rate' => LoanMath::rateString(LoanMath::rateE4((string) $rule->rate)),
                'legal_basis' => $rule->legal_basis,
                'effective_from' => $rule->effective_from->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
                'is_enabled' => $rule->is_enabled,
                'confirmed_by' => $rule->confirmed_by,
                'confirmed_on' => $rule->confirmed_on?->toDateString(),
            ]),
            'kinds' => array_map(fn (CounterpartyKind $kind) => $kind->value, CounterpartyKind::cases()),
            'canManage' => $request->user()->can('accounting.policy.manage'),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        LoanTdsRule::query()->create($this->validated($request) + ['applies_to' => LoanTdsRule::INTEREST_PAID_ON_LOANS_TAKEN, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'TDS rule added.');
    }

    public function update(Request $request, LoanTdsRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request) + ['updated_by' => $request->user()->id]);

        return back()->with('success', $rule->is_enabled ? 'TDS rule saved and in force.' : 'TDS rule saved. It is switched off: nothing is withheld.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'payee_kinds' => ['required', 'array', 'min:1'],
            'payee_kinds.*' => [Rule::enum(CounterpartyKind::class)],
            'rate' => ['required', 'string', 'max:12'],
            'legal_basis' => ['required', 'string', 'max:2000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_enabled' => ['boolean'],
            'confirmed_by' => ['nullable', 'string', 'max:120'],
            'confirmed_on' => ['nullable', 'date'],
        ]);

        try {
            $rate = LoanMath::rateE4($data['rate']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['rate' => 'Enter the rate as a percentage, like 10.']);
        }

        if ($rate > 100 * LoanMath::PERCENT) {
            throw ValidationException::withMessages(['rate' => 'A rate above 100% is not a withholding rate.']);
        }

        if (($data['is_enabled'] ?? false) && (trim((string) ($data['confirmed_by'] ?? '')) === '' || ($data['confirmed_on'] ?? null) === null)) {
            throw ValidationException::withMessages(['confirmed_by' => 'Switching TDS on needs the accountant\'s confirmation: who confirmed it and on what day.']);
        }

        return [
            'name' => $data['name'],
            'payee_kinds' => array_values(array_unique($data['payee_kinds'])),
            'rate' => LoanMath::rateString($rate),
            'legal_basis' => $data['legal_basis'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'confirmed_by' => trim((string) ($data['confirmed_by'] ?? '')) ?: null,
            'confirmed_on' => $data['confirmed_on'] ?? null,
        ];
    }
}
