<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cashier shifts (Module 3, Slice 1): open with a counted float, feed with
 * cash sales and till movements, close with a count. The over/short is
 * COMPUTED at close, never typed - the number that ends arguments has to
 * come from arithmetic, not from the person being asked about it.
 *
 * ⚠ The shift posts nothing. Cash sales reach the till through the counter
 * as always; a movement here is a custody record, and turning it into money
 * (an expense, a bank deposit) stays with the documents that do that.
 */
class PosShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('sales.sell');

        $open = DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('status', 'open')->first();

        return Inertia::render('sales/shifts', [
            'open_shift' => $open === null ? null : [
                'id' => $open->id,
                'money_account_id' => $open->money_account_id,
                'till' => DB::table('money_accounts')->where('id', $open->money_account_id)->value('name'),
                'opened_at' => $open->opened_at,
                'opened_by' => DB::table('users')->where('id', $open->opened_by)->value('name'),
                'opening_float' => $open->opening_float,
                'expected_cash' => $this->expectedCash($open),
                'movements' => DB::table('pos_cash_movements')->where('shift_id', $open->id)
                    ->orderByDesc('id')->get(['id', 'type', 'amount', 'reason', 'created_at']),
                'cash_sales' => $this->cashSales($open),
            ],
            'tills' => MoneyAccount::query()->where('type', 'cash')->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'closed_shifts' => DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('status', 'closed')
                ->orderByDesc('id')->limit(30)
                ->get()
                ->map(fn ($shift) => [
                    'id' => $shift->id,
                    'till' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
                    'opened_at' => $shift->opened_at,
                    'closed_at' => $shift->closed_at,
                    'opening_float' => $shift->opening_float,
                    'expected_cash' => $shift->expected_cash,
                    'closing_count' => $shift->closing_count,
                    'over_short' => $shift->over_short,
                    'closed_by' => DB::table('users')->where('id', $shift->closed_by)->value('name'),
                ]),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            'money_account_id' => [
                'required', Rule::exists('money_accounts', 'id'),
                // ⚠ One open shift per till: two people cannot both hold
                // custody of the same drawer.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('money_account_id', $value)->where('status', 'open')->exists()) {
                        $fail('That till already has an open shift - close it first.');
                    }
                },
            ],
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        DB::table('pos_shifts')->insert([
            'business_id' => app(BusinessContext::class)->id(),
            'money_account_id' => $data['money_account_id'],
            'opened_by' => $request->user()->id,
            'opened_at' => now(),
            'opening_float' => $data['opening_float'],
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Shift opened with a float of '.number_format((float) $data['opening_float'], 2).'.');
    }

    public function movement(Request $request, int $shift): RedirectResponse
    {
        $this->authorize('sales.sell');

        $row = DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('id', $shift)->where('status', 'open')->first();
        abort_if($row === null, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // A movement without a reason is a shortage with a head start.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::table('pos_cash_movements')->insert([
            'business_id' => app(BusinessContext::class)->id(),
            'shift_id' => $shift,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'recorded_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Movement recorded.');
    }

    public function close(Request $request, int $shift): RedirectResponse
    {
        $this->authorize('sales.sell');

        $row = DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('id', $shift)->where('status', 'open')->first();
        abort_if($row === null, 404);

        $data = $request->validate([
            'closing_count' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $expected = $this->expectedCash($row);
        $overShort = round((float) $data['closing_count'] - $expected, 2);

        DB::table('pos_shifts')->where('id', $shift)->update([
            'closed_by' => $request->user()->id,
            'closed_at' => now(),
            'closing_count' => $data['closing_count'],
            'expected_cash' => $expected,
            'over_short' => $overShort,
            'note' => $data['note'] ?? null,
            'status' => 'closed',
            'updated_at' => now(),
        ]);

        $verdict = $overShort === 0.0 ? 'exactly balanced'
            : ($overShort > 0 ? number_format($overShort, 2).' over' : number_format(-$overShort, 2).' short');

        return back()->with('success', 'Shift closed: '.$verdict.'. Book any difference as its own adjustment.');
    }

    /** Float + cash into the till during the shift + movements in - out. */
    private function expectedCash(object $shift): float
    {
        $movements = DB::table('pos_cash_movements')
            ->where('shift_id', $shift->id)
            ->selectRaw("SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END) as net")
            ->value('net') ?? 0;

        return round((float) $shift->opening_float + $this->cashSales($shift) + (float) $movements, 2);
    }

    /** Money received into this till since the shift opened. */
    private function cashSales(object $shift): float
    {
        return round((float) DB::table('customer_receipts')
            ->where('money_account_id', $shift->money_account_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->sum('amount'), 2);
    }
}
