<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Chart of Accounts screen.
 *
 * ⚠ Reading is `accounting.view`; every write is `accounting.chart.manage`,
 * applied on the routes. The split matters because renaming or re-parenting an
 * account changes what every report ever run against it meant.
 */
class AccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('accounting/accounts/index', [
            'accounts' => Account::query()
                ->inChartOrder()
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'type_label' => $account->type->label(),
                    'normal_balance' => $account->normalBalance()->value,
                    'parent_id' => $account->parent_id,
                    'is_postable' => $account->is_postable,
                    'is_active' => $account->is_active,
                    'is_system' => $account->system_slug !== null,
                    'note' => $account->note,
                ]),
            'types' => collect(AccountType::cases())->map(fn (AccountType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'normal_balance' => $type->normalBalance()->value,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $account = new Account($data);
        $account->assertConsistentWithParent();
        $account->save();

        return back()->with('success', "Account {$account->label()} created.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);

        $account->fill($data);

        // ⚠⚠ Re-typing a parent orphans the meaning of everything beneath it:
        // the children keep their own type, the group no longer matches them,
        // and the balance sheet is wrong by the total of the mismatched branch
        // while the trial balance still balances perfectly. Refuse it rather
        // than cascade - a cascade would silently re-type accounts that already
        // have history filed against them.
        if ($account->isDirty('type') && $account->children()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'This account has accounts under it. Change or move those first.',
            ]);
        }

        $account->assertConsistentWithParent();
        $account->save();

        return back()->with('success', "Account {$account->label()} updated.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        $account->assertDeletable();
        $account->delete();

        return back()->with('success', 'Account deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Account $account = null): array
    {
        return $request->validate([
            // ⚠ Restricted to digits, letters, dots and dashes because the code
            // ends up in exported files and printed reports, and it is the one
            // field an accountant types by hand a thousand times.
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/',
                Rule::unique('accounts', 'code')->ignore($account?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'is_postable' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
