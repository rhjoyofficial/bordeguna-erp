<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Actions\RecordMoneyTransfer;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordExpenseVoucher;
use App\Modules\Sales\Actions\PostTillOverShort;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Support\CounterSaleBundle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cashier shifts (Module 3, Slice 1): open with a counted float, feed with
 * cash sales and till movements, close with a count. The over/short is
 * COMPUTED at close, never typed - the number that ends arguments has to
 * come from arithmetic, not from the person being asked about it.
 *
 * ⭐ B12 (Joy's initial plan, 25 Oct 2026, gaps S1 and S2 of the sales guide):
 *   - a till movement is no longer a note. Cash in / cash out posts a real
 *     money transfer between the till and another of our accounts, or, for
 *     money spent from the drawer, an expense voucher on the chosen head. The
 *     till and the books therefore agree, and "expected" reads them from the
 *     ledger once (a movement is never counted twice);
 *   - only the shift's own cashier, or an Owner or Manager, may record a move
 *     on a shift or close it;
 *   - each shift has an end-of-shift (Z) summary page.
 *
 * ⭐ Joy's answer 5 (25 Oct 2026): the opening float is IN THE BOOKS. Opening
 * asks where it came from (a cash box or bank account) and posts a money
 * transfer into the till on the shift date ("Already in the till" posts
 * nothing: the last shift left it there). Closing may hand the counted cash
 * over to an account (default: where the float came from), a transfer out.
 * Both journals are left out of the shift's own flows, so expected = float +
 * what moved during the shift, read once. A shift opened before this has a
 * float and no transfer and keeps working; its Z summary says so.
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
            'open_shifts' => $open->map(fn (object $shift) => $this->present($shift, $request->user()))->values(),
            // ⚠ Only a cash box with its own ledger, and only one not already
            // open: a second shift on one till would split its cash between two
            // counts that could never both be right.
            'tills' => MoneyAccount::query()->where('type', 'cash')->where('is_active', true)->whereNotNull('account_id')
                ->whereNotIn('id', $open->pluck('money_account_id'))
                ->orderBy('name')->get(['id', 'name']),
            // ⭐ B12: where cash in comes from or cash out goes to. Our own
            // accounts with a ledger; never a courier's or a gateway's.
            'other_accounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            // ⭐ B12: the heads cash spent from the drawer may go to, the same
            // list the expense voucher offers.
            'expense_accounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
                ->all(),
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
    private function present(object $shift, User $user): array
    {
        $flows = $this->tillFlows($shift);

        return [
            'id' => $shift->id,
            'money_account_id' => $shift->money_account_id,
            'till' => DB::table('money_accounts')->where('id', $shift->money_account_id)->value('name'),
            'opened_at' => $this->when($shift->opened_at),
            'opened_by' => DB::table('users')->where('id', $shift->opened_by)->value('name'),
            'is_mine' => (int) $shift->opened_by === (int) $user->id,
            // ⭐ B12: may this person record a move on it or close it?
            'may_handle' => $this->mayHandle($shift, $user),
            'opening_float' => $shift->opening_float,
            // ⭐ Answer 5: the account the float came from, the hand-over's default.
            'float_from_id' => $shift->float_transfer_id === null ? null : DB::table('money_transfers')->where('id', $shift->float_transfer_id)->value('from_money_account_id'),
            'money_in' => $flows['in'],
            'money_out' => $flows['out'],
            'movements_net' => $flows['movements'],
            'expected_cash' => $this->expectedCash($shift),
            'movements' => $this->movements($shift),
        ];
    }

    /**
     * The movements of one shift, each with the document it posted.
     *
     * @return list<array<string, mixed>>
     */
    private function movements(object $shift): array
    {
        return DB::table('pos_cash_movements')
            ->leftJoin('money_transfers', 'money_transfers.id', '=', 'pos_cash_movements.money_transfer_id')
            ->leftJoin('expense_vouchers', 'expense_vouchers.id', '=', 'pos_cash_movements.expense_voucher_id')
            ->where('pos_cash_movements.shift_id', $shift->id)
            ->orderByDesc('pos_cash_movements.id')
            ->get([
                'pos_cash_movements.id', 'pos_cash_movements.type', 'pos_cash_movements.kind', 'pos_cash_movements.amount',
                'pos_cash_movements.reason', 'pos_cash_movements.created_at',
                'money_transfers.transfer_number', 'expense_vouchers.voucher_number',
            ])
            ->map(fn (object $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'kind' => $row->kind,
                'amount' => $row->amount,
                'reason' => $row->reason,
                'created_at' => $row->created_at,
                'document' => $row->transfer_number ?? $row->voucher_number,
            ])
            ->all();
    }

    private function transferNumber(?int $transferId): ?string
    {
        return $transferId === null ? null : DB::table('money_transfers')->where('id', $transferId)->value('transfer_number');
    }

    private function transferAccount(?int $transferId, string $column): ?string
    {
        $accountId = $transferId === null ? null : DB::table('money_transfers')->where('id', $transferId)->value($column);

        return $accountId === null ? null : DB::table('money_accounts')->where('id', $accountId)->value('name');
    }

    /** A timestamp as the cashier reads it, in the application's (Dhaka) time. */
    private function when(?string $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->format('d M Y, g:i A');
    }

    /**
     * ⭐ B12 (gap S1): the shift's own cashier, or an Owner or Manager. Until
     * now anybody who could sell could close anybody's shift or write a
     * movement on it, which made "who was short" unanswerable.
     */
    private function mayHandle(object $shift, User $user): bool
    {
        return (int) $shift->opened_by === (int) $user->id
            || (bool) $user->is_super_admin
            || $user->hasRole('Owner')
            || $user->hasRole('Manager');
    }

    /** @throws ValidationException */
    private function assertMayHandle(object $shift, User $user): void
    {
        if (! $this->mayHandle($shift, $user)) {
            $cashier = DB::table('users')->where('id', $shift->opened_by)->value('name');

            throw ValidationException::withMessages([
                'shift' => "This is {$cashier}'s shift. Only {$cashier}, an Owner or a Manager may record a move on it or close it.",
            ]);
        }
    }

    public function open(Request $request, RecordMoneyTransfer $transfer): RedirectResponse
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
            'opening_float' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            // ⭐ Answer 5: where the float came from. "in_till" = already in the
            // drawer (left by the last shift), nothing to post.
            'float_from' => ['nullable', 'string', 'max:20'],
        ], [
            'opening_float.regex' => 'Type the float as a plain number, like 2000 or 2000.50.',
        ]);

        $businessId = app(BusinessContext::class)->id();
        $float = Money::ofTaka((string) $data['opening_float']);
        $source = $this->floatSource($data['float_from'] ?? null, (int) $data['money_account_id'], $float, 'float_from');
        $till = MoneyAccount::query()->findOrFail((int) $data['money_account_id']);
        $user = $request->user();
        $openedAt = now();

        try {
            $number = DB::transaction(function () use ($businessId, $float, $source, $till, $user, $openedAt, $transfer) {
                $shiftId = DB::table('pos_shifts')->insertGetId([
                    'business_id' => $businessId,
                    'money_account_id' => $till->id,
                    'opened_by' => $user->id,
                    'opened_at' => $openedAt,
                    'opening_float' => $float->toTaka(),
                    'status' => 'open',
                    'created_at' => $openedAt, 'updated_at' => $openedAt,
                ]);

                if ($source === null) {
                    return null;
                }

                $moved = $transfer->handle(
                    from: $source,
                    to: $till,
                    amount: $float->toTaka(),
                    transferredOn: Carbon::today(),
                    createdBy: $user,
                    note: 'Opening float of till shift #'.$shiftId,
                );

                DB::table('pos_shifts')->where('id', $shiftId)->update([
                    'float_transfer_id' => $moved->id,
                    'float_journal_id' => $this->journalOf($moved),
                ]);

                return $moved->transfer_number;
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['float_from' => collect($e->errors())->flatten()->implode(' ')]);
        } catch (MappingNotFound|JournalException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['float_from' => $e->getMessage()]);
        }

        return back()->with('success', 'Shift opened with a float of '.$float->format().'.'.($number === null ? '' : " The float is posted as {$number}."));
    }

    /**
     * ⭐ Answer 5: the account the float comes from (or the counted cash goes
     * to). Null = nothing to post (no amount, or "in_till"). Our own account
     * with a ledger, never the till itself.
     *
     * @throws ValidationException
     */
    private function floatSource(?string $choice, int $tillId, Money $amount, string $field): ?MoneyAccount
    {
        if ($amount->isZero() || $choice === 'in_till') {
            return null;
        }

        if ($choice === null || $choice === '') {
            throw ValidationException::withMessages([$field => 'Say where the float came from, or choose "Already in the till".']);
        }

        $account = MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->whereKey((int) $choice)->first();

        if ($account === null || (int) $account->id === $tillId) {
            throw ValidationException::withMessages([$field => 'Pick another cash box or bank account of this business.']);
        }

        return $account;
    }

    /** The journal a transfer posted (its source), for leaving it out of the shift's flows. */
    private function journalOf(MoneyTransfer $transfer): ?int
    {
        $id = Journal::query()->where('source_type', $transfer->getMorphClass())->where('source_id', $transfer->id)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * ⭐ B12: a till movement POSTS. Cash in = a transfer from the chosen
     * account into the till; cash out = a transfer from the till to the chosen
     * account, or an expense voucher on the chosen head when the cash was
     * spent. The document and the movement row are written in one
     * transaction, and the row points at the document.
     */
    public function movement(Request $request, int $shift, RecordMoneyTransfer $transfer, RecordExpenseVoucher $expense): RedirectResponse
    {
        $this->authorize('sales.sell');

        $businessId = app(BusinessContext::class)->id();
        $row = DB::table('pos_shifts')->where('business_id', $businessId)->where('id', $shift)->where('status', 'open')->first();
        abort_if($row === null, 404);

        $this->assertMayHandle($row, $request->user());

        $data = $request->validate([
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'kind' => ['required', Rule::in(['transfer', 'expense'])],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            // A movement without a reason is a shortage with a head start.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'other_money_account_id' => [
                'nullable', 'required_if:kind,transfer', 'integer',
                Rule::exists('money_accounts', 'id')->where('business_id', $businessId),
            ],
            'expense_account_id' => [
                'nullable', 'required_if:kind,expense', 'integer',
                Rule::exists('accounts', 'id')->where('type', AccountType::Expense->value),
            ],
        ], [
            'amount.regex' => 'Type the amount as a plain number, like 500 or 500.50.',
            'amount.not_regex' => 'A movement needs an amount.',
            'other_money_account_id.required_if' => $request->input('type') === 'cash_in'
                ? 'Say where the cash came from.'
                : 'Say where the cash went.',
            'expense_account_id.required_if' => 'Choose the expense head the cash was spent on.',
        ]);

        // Money spent comes OUT of the drawer; nothing is "spent in".
        if ($data['kind'] === 'expense' && $data['type'] !== 'cash_out') {
            throw ValidationException::withMessages(['kind' => 'An expense is cash going out. Choose Cash out, or a transfer for cash coming in.']);
        }

        $till = MoneyAccount::query()->findOrFail($row->money_account_id);
        $user = $request->user();
        $today = Carbon::today();

        try {
            $document = DB::transaction(function () use ($data, $till, $user, $today, $transfer, $expense, $row, $businessId) {
                $transferId = null;
                $voucherId = null;

                if ($data['kind'] === 'transfer') {
                    $other = MoneyAccount::query()->findOrFail((int) $data['other_money_account_id']);
                    [$from, $to] = $data['type'] === 'cash_in' ? [$other, $till] : [$till, $other];

                    $moved = $transfer->handle(
                        from: $from,
                        to: $to,
                        amount: (string) $data['amount'],
                        transferredOn: $today,
                        createdBy: $user,
                        note: 'Till shift #'.$row->id.': '.$data['reason'],
                    );
                    $transferId = $moved->id;
                    $number = $moved->transfer_number;
                } else {
                    $voucher = $expense->handle(
                        moneyAccount: $till,
                        spentOn: $today,
                        lines: [[
                            'account' => Account::query()->findOrFail((int) $data['expense_account_id']),
                            'amount' => (string) $data['amount'],
                            'description' => $data['reason'],
                        ]],
                        createdBy: $user,
                        note: 'Till shift #'.$row->id.': '.$data['reason'],
                    );
                    $voucherId = $voucher->id;
                    $number = $voucher->voucher_number;
                }

                DB::table('pos_cash_movements')->insert([
                    'business_id' => $businessId,
                    'shift_id' => $row->id,
                    'type' => $data['type'],
                    'kind' => $data['kind'],
                    'amount' => Money::ofTaka((string) $data['amount'])->toTaka(),
                    'reason' => $data['reason'],
                    'money_transfer_id' => $transferId,
                    'expense_voucher_id' => $voucherId,
                    'recorded_by' => $user->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $number;
            });
        } catch (ValidationException $e) {
            // The posting actions name their own fields (from/to account,
            // spent_on...). This form has one place to say it: the movement.
            throw ValidationException::withMessages(['movement' => collect($e->errors())->flatten()->implode(' ')]);
        } catch (MappingNotFound|JournalException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['movement' => $e->getMessage()]);
        }

        return back()->with('success', "Movement recorded and posted as {$document}.");
    }

    public function close(Request $request, int $shift, PostTillOverShort $postOverShort, RecordMoneyTransfer $transfer): RedirectResponse
    {
        $this->authorize('sales.sell');

        $row = DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('id', $shift)->where('status', 'open')->first();
        abort_if($row === null, 404);

        $this->assertMayHandle($row, $request->user());

        $data = $request->validate([
            'closing_count' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            // ⭐ Answer 5: optional; "in_till" or empty = the cash stays in the drawer.
            'hand_over_to' => ['nullable', 'string', 'max:20'],
        ]);

        $handOver = Money::ofTaka(number_format((float) $data['closing_count'], 2, '.', ''));
        $handOverTo = in_array($data['hand_over_to'] ?? null, [null, '', 'in_till'], true)
            ? null
            : $this->floatSource($data['hand_over_to'], (int) $row->money_account_id, $handOver, 'hand_over_to');

        // ⚠ Double click: the second close finds the shift no longer open and
        // changes nothing (the update is conditional on status).
        $expected = $this->expectedCash($row);
        $overShort = round((float) $data['closing_count'] - $expected, 2);

        $closed = DB::table('pos_shifts')->where('id', $shift)->where('status', 'open')->update([
            'closed_by' => $request->user()->id,
            'closed_at' => now(),
            'closing_count' => $data['closing_count'],
            'expected_cash' => $expected,
            'over_short' => $overShort,
            'note' => $data['note'] ?? null,
            'status' => 'closed',
            'updated_at' => now(),
        ]);

        if ($closed === 0) {
            return back()->with('success', 'That shift is already closed.');
        }

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

        // ⭐ Answer 5: hand the counted cash over (after the difference is
        // booked, so the till holds exactly what was counted). Like the
        // difference, a refusal never reopens the shift: it is said.
        if ($handOverTo !== null) {
            try {
                $from = MoneyAccount::query()->findOrFail($row->money_account_id);
                $moved = DB::transaction(function () use ($transfer, $from, $handOverTo, $handOver, $request, $shift) {
                    $moved = $transfer->handle(
                        from: $from,
                        to: $handOverTo,
                        amount: $handOver->toTaka(),
                        transferredOn: Carbon::today(),
                        createdBy: $request->user(),
                        note: 'Counted cash of till shift #'.$shift.' handed over',
                    );
                    DB::table('pos_shifts')->where('id', $shift)->update(['handover_transfer_id' => $moved->id, 'handover_journal_id' => $this->journalOf($moved)]);

                    return $moved;
                });
                $booked .= " The counted cash is handed over to {$handOverTo->name} as {$moved->transfer_number}.";
            } catch (\Throwable $e) {
                report($e);
                $why = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();
                $booked .= " The hand-over could NOT be posted ({$why}); record it as a money transfer.";
            }
        }

        return back()->with('success', 'Shift closed: '.$verdict.'.'.$booked);
    }

    /**
     * ⭐ B12: the end-of-shift (Z) summary. Sales by payment method, cash in
     * and out, expected against counted, and the difference. Read-only; a
     * shift still open shows the live expectation and no count.
     */
    public function summary(Request $request, int $shift): Response
    {
        $this->authorize('sales.sell');

        $row = DB::table('pos_shifts')->where('business_id', app(BusinessContext::class)->id())->where('id', $shift)->first();
        abort_if($row === null, 404);

        $until = $row->closed_at ?? now()->toDateTimeString();
        $flows = $this->tillFlows($row);
        $movements = $this->movements($row);

        // The counter sales this cashier rang up during the shift. An invoice
        // the same person raised on the invoice screen is not a counter sale
        // (CounterSaleBundle recognises one), so it is left out.
        $byMethod = [];
        $onAccount = 0;
        $billed = 0;
        $count = 0;
        $voided = 0;

        $invoices = SalesInvoice::query()
            ->where('created_by', $row->opened_by)
            ->where('created_at', '>=', $row->opened_at)
            ->where('created_at', '<=', $until)
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $bundle = CounterSaleBundle::of($invoice);

            if ($bundle === null) {
                continue;
            }

            if ($invoice->isReversed()) {
                $voided++;

                continue;
            }

            $count++;
            $total = Money::ofTaka((string) $invoice->total)->minor;
            $billed += $total;
            $paid = 0;

            foreach ($bundle->receipts as $receipt) {
                /** @var CustomerReceipt $receipt */
                if ($receipt->isReversed()) {
                    continue;
                }

                $name = (string) DB::table('money_accounts')->where('id', $receipt->money_account_id)->value('name');
                $byMethod[$name] = ($byMethod[$name] ?? 0) + $receipt->amountMoney()->minor;
                $paid += $receipt->amountMoney()->minor;
            }

            $onAccount += max(0, $total - $paid);
        }

        ksort($byMethod);

        $taka = fn (int $minor) => Money::ofMinor($minor)->toTaka();
        $in = collect($movements)->where('type', 'cash_in')->sum(fn ($m) => Money::ofTaka((string) $m['amount'])->minor);
        $out = collect($movements)->where('type', 'cash_out')->sum(fn ($m) => Money::ofTaka((string) $m['amount'])->minor);
        $expected = $row->status === 'closed' ? (float) $row->expected_cash : $this->expectedCash($row);

        return Inertia::render('sales/shift-summary', [
            'shift' => [
                'id' => $row->id,
                'status' => $row->status,
                'till' => DB::table('money_accounts')->where('id', $row->money_account_id)->value('name'),
                'cashier' => DB::table('users')->where('id', $row->opened_by)->value('name'),
                'closed_by' => $row->closed_by === null ? null : DB::table('users')->where('id', $row->closed_by)->value('name'),
                'opened_at' => $this->when($row->opened_at),
                'closed_at' => $this->when($row->closed_at),
                'note' => $row->note,
            ],
            'sales' => [
                'count' => $count,
                'voided' => $voided,
                'billed' => $taka($billed),
                'by_method' => collect($byMethod)->map(fn (int $minor, string $name) => ['name' => $name, 'amount' => $taka($minor)])->values()->all(),
                'on_account' => $taka($onAccount),
            ],
            'cash' => [
                'opening_float' => Money::ofTaka((string) $row->opening_float)->toTaka(),
                // ⭐ Answer 5: the transfer that posted the float, or why there is none.
                'float_document' => $this->transferNumber($row->float_transfer_id),
                'float_from' => $this->transferAccount($row->float_transfer_id, 'from_money_account_id'),
                'float_not_posted' => $row->float_transfer_id === null && (float) $row->opening_float > 0,
                'handover_document' => $this->transferNumber($row->handover_transfer_id),
                'handover_to' => $this->transferAccount($row->handover_transfer_id, 'to_money_account_id'),
                'ledger_in' => number_format($flows['in'], 2, '.', ''),
                'ledger_out' => number_format($flows['out'], 2, '.', ''),
                'moves_in' => $taka((int) $in),
                'moves_out' => $taka((int) $out),
                'notes_net' => number_format($flows['movements'], 2, '.', ''),
                'expected' => number_format($expected, 2, '.', ''),
                'counted' => $row->closing_count === null ? null : Money::ofTaka((string) $row->closing_count)->toTaka(),
                'difference' => $row->over_short === null ? null : Money::ofTaka((string) $row->over_short)->toTaka(),
            ],
            'movements' => $movements,
        ]);
    }

    /**
     * What should be in the drawer: the float, plus every movement of this
     * till's money the LEDGER recorded since the shift opened, plus the old
     * custody notes typed before movements posted.
     *
     * ⚠⚠ Read from the journal lines stamped with the till (21 Sep 2026), not
     * from receipts alone. The old sum counted money received and nothing that
     * left: a cash refund, a payment voucher paid from the till or cash moved to
     * the bank took money out of the drawer and not out of "expected", so the
     * count read short by exactly that much and the shortfall was booked to
     * Cash Over and Short. Every one of those posts a line on the till (its own
     * ledger since the same day), so the ledger is the one complete answer.
     *
     * ⚠ B12 (25 Oct 2026): a movement now POSTS, so it is already in the ledger
     * lines and is NOT added again; only the old note-only rows (no kind) are.
     * Expected = float + cash sales + cash in - cash out, with every one of
     * those read once.
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
            ->when($shift->closed_at ?? null, fn ($query, $closedAt) => $query->where('created_at', '<=', $closedAt))
            // The difference booked at close is not money that moved in the shift.
            ->when($shift->over_short_journal_id ?? null, fn ($query, $journalId) => $query->where('journal_id', '!=', $journalId))
            // ⭐ Answer 5: the float is counted once, as the float; the hand-over
            // is what left after the count.
            ->when($shift->float_journal_id ?? null, fn ($query, $journalId) => $query->where('journal_id', '!=', $journalId))
            ->when($shift->handover_journal_id ?? null, fn ($query, $journalId) => $query->where('journal_id', '!=', $journalId))
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) as money_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END), 0) as money_out')
            ->first();

        $movements = DB::table('pos_cash_movements')
            ->where('shift_id', $shift->id)
            ->whereNull('kind')
            ->selectRaw("SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END) as net")
            ->value('net') ?? 0;

        return [
            'in' => round(((int) $ledger->money_in) / 100, 2),
            'out' => round(((int) $ledger->money_out) / 100, 2),
            'movements' => round((float) $movements, 2),
        ];
    }
}
