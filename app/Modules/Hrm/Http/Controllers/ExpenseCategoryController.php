<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Support\ExpenseCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The COA mapping behind the expense categories - the accountant's side of
 * the structure. Staff pick a name; THIS screen decides which account the
 * name lands in. Remapping changes future approvals only: posted journals
 * are history and stay exactly where they were posted.
 */
class ExpenseCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('hrm.payroll.manage');

        $businessId = app(BusinessContext::class)->id();
        ExpenseCategories::ensureDefaults($businessId);

        $accountNames = Account::query()->whereIn(
            'id',
            DB::table('expense_categories')->where('business_id', $businessId)->pluck('account_id'),
        )->get(['id', 'code', 'name'])->keyBy('id');

        return Inertia::render('hrm/expense-categories', [
            'categories' => DB::table('expense_categories')
                ->where('business_id', $businessId)
                ->orderBy('sort')
                ->get()
                ->map(fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'name_bn' => $category->name_bn ?? null,
                    'account_id' => $category->account_id,
                    'account' => ($accountNames[$category->account_id]->code ?? '?').' - '.($accountNames[$category->account_id]->name ?? '?'),
                    'is_exception' => (bool) $category->is_exception,
                    'receipt_required' => (bool) ($category->receipt_required ?? true),
                    'is_active' => (bool) $category->is_active,
                ]),
            'accounts' => Account::query()
                ->where('type', 'expense')
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->code.' - '.$account->name,
                ]),
        ]);
    }

    /**
     * Add a category the list did not have.
     *
     * ⚠⚠ THE SCREEN COULD ONLY REMAP, NEVER CREATE, and that is what made the
     * Hostinger bill homeless (Joy, 9 Sep 2026): the account existed, the
     * category did not, and the only way to file the spend was the catch-all the
     * accountant's own document forbids as a default.
     *
     * ⛔ EMPLOYEES DO NOT CREATE CATEGORIES, and this staying behind the payroll
     * permission is the enforcement. A category IS an accounting decision -
     * which head the money lands in - and letting the person spending it invent
     * one puts the classification in the hands of whoever is least able to get
     * it right. When something genuinely new comes up they use the catch-all,
     * and the accountant adds the proper category and remaps it here.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('expense_categories', 'name')->where('business_id', $businessId),
            ],
            'name_bn' => ['nullable', 'string', 'max:100'],
            // ⚠ Not restricted to 6xxx. Product Inward Freight maps to 5300,
            // which is Cost of Sales, and the mapping has always been per
            // category rather than per band.
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_postable', true)],
            'receipt_required' => ['boolean'],
        ], [
            'name.unique' => 'A category with that name already exists. Switch it back on rather than adding a second one.',
        ]);

        DB::table('expense_categories')->insert([
            'business_id' => $businessId,
            'name' => $data['name'],
            'name_bn' => $data['name_bn'] ?? null,
            'account_id' => $data['account_id'],
            'is_exception' => false,
            'receipt_required' => $data['receipt_required'] ?? true,
            'is_active' => true,
            'sort' => (int) DB::table('expense_categories')->where('business_id', $businessId)->max('sort') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', "Category \"{$data['name']}\" added. Staff can pick it on their next claim.");
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $row = DB::table('expense_categories')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $category)->first();
        abort_if($row === null, 404);

        $data = $request->validate([
            'account_id' => ['sometimes', 'integer', Rule::exists('accounts', 'id')->where('type', 'expense')],
            'is_active' => ['sometimes', 'boolean'],
            // Which categories arrive with no piece of paper. Rickshaw fare and
            // tea are the whole reason this exists.
            'receipt_required' => ['sometimes', 'boolean'],
        ]);

        DB::table('expense_categories')->where('id', $row->id)
            ->update($data + ['updated_at' => now()]);

        return back()->with('success', 'Category updated. Future approvals follow the new mapping; posted journals stay as posted.');
    }
}
