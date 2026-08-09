<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the business's money actually sits: bank accounts, cash boxes, bKash.
 *
 * ⚠⚠ The table has existed since Phase 1.1 and had NO SCREEN, so production has
 * carried zero rows and there was no way to add one. That mattered more than it
 * looked: an opening bank balance cannot be posted until the bank account it
 * belongs to exists, so a missing screen was quietly blocking the thing this
 * whole phase is building towards.
 *
 * ⚠ It still carries no balance. That is a ledger question, answered by the
 * journal lines, and putting a number here would recreate the second source of
 * truth four Inventory slices were spent removing.
 *
 * ⚠ NO DELETE. The model's own design says "deactivated, never deleted": a
 * payment made in August must still be nameable in December, and payroll
 * disbursement already points here. Offering a delete button would have
 * contradicted the table it manages.
 */
class MoneyAccountController extends Controller
{
    public function index(): Response
    {
        // ⚠ The ledger label is resolved from THIS side rather than through a
        // relation on MoneyAccount. Foundation is the base module and
        // references nothing above it - one lookup here is cheaper than
        // inverting that for an eager load.
        $ledgerAccounts = Account::query()->get()->keyBy('id');

        return Inertia::render('accounting/money-accounts/index', [
            'accounts' => MoneyAccount::query()
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (MoneyAccount $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'type_label' => $account->type->label(),
                    'account_number' => $account->account_number,
                    'bank_name' => $account->bank_name,
                    'branch' => $account->branch,
                    'routing_number' => $account->routing_number,
                    'provider' => $account->provider,
                    'note' => $account->note,
                    'is_active' => $account->is_active,
                    'account_id' => $account->account_id,
                    'ledger_account' => $ledgerAccounts->get($account->account_id)?->label(),
                    // ⚠ Legacy rows created before the rule above can still be
                    // unmapped, and a payment from one WILL fail. The screen says
                    // so rather than waiting for the till to.
                    'is_unmapped' => $account->account_id === null,
                ]),
            // ⚠ Counted server-side so the warning cannot drift from the rows.
            'unmappedCount' => MoneyAccount::whereNull('account_id')->count(),
            'types' => collect(MoneyAccountType::cases())->map(fn (MoneyAccountType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            // ⚠ Assets only. A cash box reporting under Sales Revenue is a typo
            // nobody would ever catch by reading a balance sheet.
            'ledgerAccounts' => $ledgerAccounts
                ->filter(fn (Account $account) => $account->is_postable && $account->is_active && $account->type === AccountType::Asset)
                ->sortBy('code')
                ->values()
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = MoneyAccount::create($this->validated($request));

        return back()->with('success', "{$account->label()} added.");
    }

    public function update(Request $request, MoneyAccount $moneyAccount): RedirectResponse
    {
        $moneyAccount->update($this->validated($request));

        return back()->with('success', "{$moneyAccount->label()} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(MoneyAccountType::class)],
            // ⚠ Everything below is optional because CASH needs none of it,
            // which is exactly why cash is the only kind that can never be
            // wrong about how somebody was paid.
            'account_number' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'routing_number' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            // ⚠⚠ DELIBERATELY STILL NULLABLE, and this was nearly changed in
            // error on 9 August.
            //
            // The end-to-end cycle found that a money account with a null
            // `account_id` fails only when somebody tries to PAY A SUPPLIER,
            // three steps and possibly months later, with MappingNotFound at the
            // till. The obvious fix - make it required here - would have broken a
            // decision this codebase made on purpose and TESTED: "a cash box may
            // exist before anybody has decided what it reports under", because
            // refusing to record a real account would lose the fact that the
            // account exists at all.
            //
            // ▶ So the gap is closed by SURFACING rather than refusing: the index
            // exposes `is_unmapped`, the screen names the consequence in the row,
            // and a banner counts them. Setup order is preserved and the state is
            // impossible to miss.
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'is_active' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
