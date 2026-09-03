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
                    'account_id' => $category->account_id,
                    'account' => ($accountNames[$category->account_id]->code ?? '?').' - '.($accountNames[$category->account_id]->name ?? '?'),
                    'is_exception' => (bool) $category->is_exception,
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
        ]);

        DB::table('expense_categories')->where('id', $row->id)
            ->update($data + ['updated_at' => now()]);

        return back()->with('success', 'Category updated. Future approvals follow the new mapping; posted journals stay as posted.');
    }
}
