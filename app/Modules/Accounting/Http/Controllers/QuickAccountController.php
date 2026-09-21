<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\MoneyLedgers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A ledger head created from INSIDE another form (Joy's accountant, 19 Sep 2026).
 *
 * Recording an expense and finding the head missing used to mean: close the
 * form, lose what was typed, open the chart, add the head, come back, start
 * again. Every ledger picker now offers "New ledger head", which talks to these
 * two endpoints and hands the new account straight back to the picker.
 *
 * ⚠ JSON, not Inertia, on purpose. An Inertia redirect would re-render the page
 * underneath the open form; the picker only needs the new row's id and label,
 * and then reloads just the one prop that feeds its list.
 *
 * ⚠⚠ The same permission as the chart screen (`accounting.chart.manage`, on the
 * routes). A smaller door into the chart is still a door into the chart: a head
 * created here appears in all four businesses, exactly as one created there.
 */
class QuickAccountController extends Controller
{
    /** The headings a new head of this type may sit under, each with its next free code. */
    public function options(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::enum(AccountType::class)]]);

        $headings = Account::query()
            ->where('type', $data['type'])
            ->where('is_postable', false)
            ->where('is_active', true)
            // Cash, bank and wallet ledgers are made by Bank & cash, one per account.
            ->whereNotIn('id', MoneyLedgers::groupIds())
            ->inChartOrder()
            ->get();

        return response()->json([
            'headings' => $headings->map(fn (Account $heading) => [
                'id' => $heading->id,
                'label' => $heading->label(),
                'suggested_code' => $this->nextCodeUnder($heading),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('accounts', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            // ⚠ Required here although the chart screen allows a root account:
            // a head created in passing must land inside a group, or the profit
            // and loss prints it under no section heading at all.
            'parent_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('is_postable', 0)->whereNotIn('id', MoneyLedgers::groupIds()))],
        ]);

        $account = new Account($data + ['is_postable' => true, 'is_active' => true]);
        $account->assertConsistentWithParent();
        $account->save();

        return response()->json([
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'label' => $account->label(),
        ], 201);
    }

    /**
     * The next unused code after the heading's highest child, kept inside the
     * band before the next heading. Null when the band is full or the codes are
     * not plain numbers - the person types one instead.
     */
    private function nextCodeUnder(Account $heading): ?string
    {
        if (! ctype_digit($heading->code)) {
            return null;
        }

        $taken = Account::query()->pluck('code')->filter(fn ($code) => ctype_digit((string) $code))->map(fn ($code) => (int) $code);
        $highestChild = Account::query()->where('parent_id', $heading->id)->pluck('code')
            ->filter(fn ($code) => ctype_digit((string) $code))->map(fn ($code) => (int) $code)->max();

        $ceiling = $taken->filter(fn (int $code) => $code > (int) $heading->code)
            ->reject(fn (int $code) => Account::query()->where('code', (string) $code)->where('parent_id', $heading->id)->exists())
            ->min() ?? PHP_INT_MAX;

        for ($candidate = ($highestChild ?? (int) $heading->code) + 1; $candidate < $ceiling; $candidate++) {
            if (! $taken->contains($candidate)) {
                return str_pad((string) $candidate, strlen($heading->code), '0', STR_PAD_LEFT);
            }
        }

        return null;
    }
}
