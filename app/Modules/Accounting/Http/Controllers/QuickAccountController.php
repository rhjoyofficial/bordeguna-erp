<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\MoneyLedgers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * ⚠⚠ The same permission as creating on the chart screen
 * (`accounting.chart.head.create`, on the routes). A smaller door into the chart
 * is still a door into the chart: a head created here appears in all four
 * businesses, exactly as one created there.
 *
 * 26 Sep 2026 (Joy, the accountant): the heading the head needs may not exist
 * either. `store` therefore also takes `new_heading` {parent_id, code, name}: a
 * sub-heading is created under an EXISTING heading of the same type and the
 * head goes under it, both in one transaction. ⚠ Never a root heading from
 * here: the six roots shape the statements and stay on the chart screen with
 * `chart.structure.manage` (ChatGPT review, point d).
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
                // For "+ New heading" under this one: a round-ten code whose
                // whole band of ten is free, or null and the person types one.
                'suggested_heading_code' => $this->nextHeadingCodeUnder($heading),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $headingRule = Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('is_postable', 0)->where('is_active', 1)->whereNotIn('id', MoneyLedgers::groupIds()));

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('accounts', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            // ⚠ Required here although the chart screen allows a root account:
            // a head created in passing must land inside a group, or the profit
            // and loss prints it under no section heading at all. Absent only
            // when a new sub-heading is being made in the same request.
            'parent_id' => ['required_without:new_heading', 'nullable', 'integer', $headingRule],
            'new_heading' => ['nullable', 'array:parent_id,code,name'],
            'new_heading.parent_id' => ['required_with:new_heading', 'integer', $headingRule],
            'new_heading.code' => ['required_with:new_heading', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('accounts', 'code'), 'different:code'],
            'new_heading.name' => ['required_with:new_heading', 'string', 'max:255'],
        ]);

        $account = DB::transaction(function () use ($data) {
            $parentId = $data['parent_id'] ?? null;

            if (isset($data['new_heading'])) {
                $heading = new Account([
                    'code' => $data['new_heading']['code'],
                    'name' => $data['new_heading']['name'],
                    'type' => $data['type'],
                    'parent_id' => $data['new_heading']['parent_id'],
                    'is_postable' => false,
                    'is_active' => true,
                ]);
                $heading->assertConsistentWithParent();
                $heading->save();
                $parentId = $heading->id;
            }

            $account = new Account([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'parent_id' => $parentId,
                'is_postable' => true,
                'is_active' => true,
            ]);
            $account->assertConsistentWithParent();
            $account->save();

            return $account;
        });

        return response()->json([
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'label' => $account->label(),
        ], 201);
    }

    /**
     * A code for a NEW sub-heading under `$heading`: the first round-ten number
     * above the heading's highest child whose ten codes are all free and which
     * stays below the next heading outside this branch. Null when there is no
     * such room or the codes are not plain numbers.
     */
    private function nextHeadingCodeUnder(Account $heading): ?string
    {
        if (! ctype_digit($heading->code)) {
            return null;
        }

        $all = Account::query()->get(['id', 'code', 'parent_id']);
        $taken = $all->pluck('code')->filter(fn ($code) => ctype_digit((string) $code))->map(fn ($code) => (int) $code);

        $branch = [$heading->id => true];
        do {
            $before = count($branch);
            foreach ($all as $row) {
                if ($row->parent_id !== null && isset($branch[$row->parent_id])) {
                    $branch[$row->id] = true;
                }
            }
        } while (count($branch) > $before);

        $ceiling = $all->reject(fn ($row) => isset($branch[$row->id]))
            ->pluck('code')->filter(fn ($code) => ctype_digit((string) $code))->map(fn ($code) => (int) $code)
            ->filter(fn (int $code) => $code > (int) $heading->code)->min() ?? PHP_INT_MAX;

        $highestInBranch = $all->filter(fn ($row) => isset($branch[$row->id]) && $row->id !== $heading->id)
            ->pluck('code')->filter(fn ($code) => ctype_digit((string) $code))->map(fn ($code) => (int) $code)->max() ?? (int) $heading->code;

        for ($candidate = (int) (ceil(($highestInBranch + 1) / 10) * 10); $candidate + 9 < $ceiling; $candidate += 10) {
            $band = range($candidate, $candidate + 9);

            if ($taken->intersect($band)->isEmpty()) {
                return str_pad((string) $candidate, strlen($heading->code), '0', STR_PAD_LEFT);
            }
        }

        return null;
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
