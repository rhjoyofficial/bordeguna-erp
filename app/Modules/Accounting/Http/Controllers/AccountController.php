<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Accounting\Support\MoneyLedgers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Chart of Accounts screen.
 *
 * ⚠ Reading is `accounting.view`. Since 26 Sep 2026 the writes are split
 * (Support\ChartPermissions): create needs `chart.head.create`, update
 * `chart.head.update`, delete `chart.head.delete`, on the routes; and anything
 * STRUCTURAL - a root heading, a re-parent, a re-type, head to heading or back,
 * deactivating a heading, a system head's code - additionally needs
 * `chart.structure.manage`, checked here, because re-parenting an account
 * changes what every report ever run against it meant.
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
            'money_group_ids' => MoneyLedgers::groupIds(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $account = new Account($data);

        // ⚠ A root heading changes the shape of the statements: Owner only.
        if ($account->parent_id === null) {
            $this->authorizeStructure('A top-level heading changes the layout of every statement. Ask the Owner, or choose a heading to sit under.');
        }

        $account->assertConsistentWithParent();
        $this->assertTreeStaysSound($account);
        $account->save();

        return back()->with('success', "Account {$account->label()} created.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);

        $account->fill($data);

        if ($this->isStructural($account)) {
            $this->authorizeStructure('Moving, re-typing or regrouping an account, or retiring a heading, needs the chart structure permission. Ask the Owner.');
        }

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
        $this->assertTreeStaysSound($account);
        $account->save();

        return back()->with('success', "Account {$account->label()} updated.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        // ⚠ C-INV-E2, the same defect in Accounting. `assertDeletable` refuses
        // under the key `account`, and this screen renders code, name, type and
        // parent_id but never `account`, while delete is a bare button with no
        // form behind it at all. Both refusals were therefore invisible: the row
        // simply stayed put. Flashed instead, which is guaranteed visible.
        try {
            $account->assertDeletable();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        // ⚠ A cash, bank or wallet head belongs to its Bank & cash account, and
        // the database would refuse the delete with an error page anyway.
        if (MoneyLedgers::isMoneyHead($account) || DB::table('money_accounts')->where('account_id', $account->id)->exists()) {
            return back()->with('error', "{$account->label()} is the ledger of a Bank & cash account. Deactivate the account there instead.");
        }

        if (DB::table('journal_lines')->where('account_id', $account->id)->exists()) {
            return back()->with('error', "{$account->label()} has postings, so it cannot be deleted. Deactivate it instead.");
        }

        $account->delete();

        return back()->with('success', 'Account deleted.');
    }

    /**
     * What counts as STRUCTURE on an edit: everything except a rename, a note,
     * and switching a plain head on or off. Root, parent, type and postability
     * decide where a balance lands on a statement; a heading's active flag hides
     * a whole branch; a system head's code is what seeders and exports key on.
     */
    private function isStructural(Account $account): bool
    {
        if ($account->isDirty(['parent_id', 'type', 'is_postable'])) {
            return true;
        }

        if ($account->isDirty('is_active') && ! $account->is_postable) {
            return true;
        }

        if ($account->isDirty('code') && $account->system_slug !== null) {
            return true;
        }

        return $account->getOriginal('parent_id') === null && $account->isDirty();
    }

    private function authorizeStructure(string $message): void
    {
        if (! request()->user()->can(ChartPermissions::STRUCTURE)) {
            throw ValidationException::withMessages(['parent_id' => $message]);
        }
    }

    /**
     * The rules that keep the chart a tree the statements can read, checked on
     * the server because the form offers every account of the same type.
     *
     * ⚠⚠ Cash, bank and wallet ledgers are made by Bank & cash, one per money
     * account (21 Sep 2026, Support\MoneyLedgers). A head typed in by hand under
     * Bank Accounts would be a bank ledger with no bank behind it, which the
     * posting engine refuses anyway, so the chart does not offer the way in.
     */
    private function assertTreeStaysSound(Account $account): void
    {
        $moneyGroups = MoneyLedgers::groupIds();
        $parentChanged = ! $account->exists || $account->isDirty('parent_id');

        if ($account->exists && in_array((int) $account->getOriginal('parent_id'), $moneyGroups, true)
            && collect($account->getDirty())->keys()->diff(['note', 'updated_at'])->isNotEmpty()) {
            throw ValidationException::withMessages([
                'name' => 'This is the ledger of a Bank & cash account. Rename, move or deactivate it from Accounting > Bank & cash.',
            ]);
        }

        if ($parentChanged && $account->parent_id !== null && in_array((int) $account->parent_id, $moneyGroups, true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Cash, bank and wallet ledgers are made in Accounting > Bank & cash: add the account there and it gets its own ledger.',
            ]);
        }

        if ($account->exists && in_array($account->id, $moneyGroups, true) && $account->isDirty(['code', 'type', 'parent_id', 'is_postable'])) {
            throw ValidationException::withMessages([
                'code' => 'This group holds the cash, bank or wallet ledgers. Only its name can change.',
            ]);
        }

        if ($parentChanged && $account->parent_id !== null && Account::whereKey($account->parent_id)->value('is_postable')) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose a heading to sit under, not an account that is posted to: the statements would skip it.',
            ]);
        }

        if ($account->exists && $account->is_postable && $account->isDirty('is_postable') && $account->children()->exists()) {
            throw ValidationException::withMessages([
                'is_postable' => 'This account has accounts under it, so it stays a heading.',
            ]);
        }

        // ⚠ The 4900 rule: a head with history cannot become a heading, or its
        // postings would sit on an account nothing can post to or reverse.
        if ($account->exists && ! $account->is_postable && $account->isDirty('is_postable')
            && (DB::table('journal_lines')->where('account_id', $account->id)->exists()
                || DB::table('money_accounts')->where('account_id', $account->id)->exists())) {
            throw ValidationException::withMessages([
                'is_postable' => 'This account already has postings or a Bank & cash account, so it cannot become a heading.',
            ]);
        }
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
