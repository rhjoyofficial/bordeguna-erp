<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Actions\PostTillOverShort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    /**
     * ⚠⚠ EVERY OPEN TILL, not the first one found (21 Sep 2026). The table
     * always allowed one open shift per till, but this screen showed only the
     * first, so a second till could be opened and never seen or closed here.
     */
    public function index(Request $request): Response
    {
        $this->authorize('sales.sell');

        $businessId = app(BusinessContext::class)->id();
        $open = DB::table('pos_shifts')->where('business_id', $businessId)->where('status', 'open')->orderBy('opened_at')->get();

        return Inertia::render('sales/shifts', [
            'open_shifts' => $open->map(fn (object $shift) => $this->present($shift, $request->user()->id))->values(),
            // ⚠ Only a cash box with its own ledger, and only one not already
            // open: a second shift on one till would split its cash between two
            // counts that could never both be right.
            'tills' => MoneyAccount::query()->where('type', 'cash')->where('is_active', true)->whereNotNull('account_id')
                ->whereNotIn('id', $open->pluck('money_account_id'))
                ->orderBy('name')->get(['id', 'name']),
            'closed_shifts' => DB::table('pos_shifts')->where('business_id', $businessId)->where('status', 'closed')
                ->orderByDesc('id')->limit(30)
                ->get()
                ->map(fn ($shift) => [
                    'id' => $shift->id,
                    'till' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
                    'opened_at' => $this->when($shift->opened_at),
                    'closed_at' => $this->when($shift->closed_at),
                    'opening_float' => $shift->opening_float,
                    'expected_cash' => $shift->expected_cash,
                    'closing_count' => $shift->closing_count,
                    'over_short' => $shift->over_short,
                    'closed_by' => DB::table('users')->where('id', $shift->closed_by)->value('name'),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(object $shift, int $userId): array
    {
        $flows = $this->tillFlows($shift);

        return [
            'id' => $shift->id,
            'money_account_id' => $shift->money_account_id,
            'till' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
            'opened_at' => $this->when($shift->opened_at),
            'opened_by' => DB::table('users')->where('id', $shift->opened_by)->value('name'),
            'is_mine' => (int) $shift->opened_by === $userId,
            'opening_float' => $shift->opening_float,
            'money_in' => $flows['in'],
            'money_out' => $flows['out'],
            'movements_net' => $flows['movements'],
            'expected_cash' => $this->expectedCash($shift),
            'movements' => DB::table('pos_cash_movements')->where('shift_id', $shift->id)
                ->orderByDesc('id')->get(['id', 'type', 'amount', 'reason', 'created_at']),
        ];
    }

    /** A timestamp as the cashier reads it, in the application's (Dhaka) time. */
    private function when(?string $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->format('d M Y, g:i A');
    }

    public function open(Request $request): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            // ⚠ A cash box of THIS business with its own ledger: a bare exists
            // accepted another business's account, or a bank account, as a till.
            'money_account_id' => [
                'required',
                Rule::exists('money_accounts', 'id')
                    ->where('business_id', app(BusinessContext::class)->id())
                    ->where('type', 'cash')
                    ->where('is_active', true)
                    ->whereNotNull('account_id'),
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

    public function close(Request $request, int $shift, PostTillOverShort $postOverShort): RedirectResponse
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

        // ⭐ The difference is BOOKED, not left for somebody to remember
        // (accountant meeting 19 Sep 2026, F5). The shift is already closed
        // above: a closed month or missing setup must never undo a day's close,
        // so a refusal here is reported and the shift stays closed.
        $booked = '';

        try {
            $till = MoneyAccount::query()->find($row->money_account_id);
            $journal = $till === null ? null : $postOverShort->handle(
                $till, Money::ofTaka(number_format($overShort, 2, '.', '')), $shift, Carbon::today(), $request->user(),
            );

            if ($journal !== null) {
                DB::table('pos_shifts')->where('id', $shift)->update(['over_short_journal_id' => $journal->id]);
                $booked = ' The difference is booked to Cash Over and Short.';
            }
        } catch (\Throwable $e) {
            report($e);
            $booked = ' The difference could NOT be booked ('.$e->getMessage().'); record it with a journal voucher.';
        }

        return back()->with('success', 'Shift closed: '.$verdict.'.'.$booked);
    }

    /**
     * What should be in the drawer: the float, plus every movement of this
     * till's money the LEDGER recorded since the shift opened, plus the
     * custody movements typed on this screen.
     *
     * ⚠⚠ Read from the journal lines stamped with the till (21 Sep 2026), not
     * from receipts alone. The old sum counted money received and nothing that
     * left: a cash refund, a payment voucher paid from the till or cash moved to
     * the bank took money out of the drawer and not out of "expected", so the
     * count read short by exactly that much and the shortfall was booked to
     * Cash Over and Short. Every one of those posts a line on the till (its own
     * ledger since the same day), so the ledger is the one complete answer.
     */
    private function expectedCash(object $shift): float
    {
        $flows = $this->tillFlows($shift);

        return round((float) $shift->opening_float + $flows['in'] - $flows['out'] + $flows['movements'], 2);
    }

    /** @return array{in: float, out: float, movements: float} */
    private function tillFlows(object $shift): array
    {
        $ledger = DB::table('journal_lines')
            ->where('business_id', $shift->business_id)
            ->where('money_account_id', $shift->money_account_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) as money_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END), 0) as money_out')
            ->first();

        $movements = DB::table('pos_cash_movements')
            ->where('shift_id', $shift->id)
            ->selectRaw("SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END) as net")
            ->value('net') ?? 0;

        return [
            'in' => round(((int) $ledger->money_in) / 100, 2),
            'out' => round(((int) $ledger->money_out) / 100, 2),
            'movements' => round((float) $movements, 2),
        ];
    }
}
