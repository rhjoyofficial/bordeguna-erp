<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\EmployeeCash;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountLifetime;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\MasterRecords;
use App\Support\Lifetime\LifetimeCsv;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where the business's money actually sits: bank accounts, cash boxes, bKash.
 *
 * ⚠⚠ The table has existed since Phase 1.1 and had NO SCREEN, so production has
 * carried zero rows and there was no way to add one. That mattered more than it
 * looked: an opening bank balance cannot be posted until the bank account it
 * belongs to exists, so a missing screen was quietly blocking the thing this
 * whole phase is building towards.
 *
 * ⚠ The balance is READ from the posted journal lines (MoneyAvailability, the
 * source Cash position and the payment guard use), never stored on the money
 * account: storing it would recreate the second source of truth four Inventory
 * slices were spent removing (F32, 17 Oct 2026: the owner had to open Cash
 * position to see any money at all).
 *
 * ⚠ NO DELETE. The model's own design says "deactivated, never deleted": a
 * payment made in August must still be nameable in December, and payroll
 * disbursement already points here. Offering a delete button would have
 * contradicted the table it manages.
 */
class MoneyAccountController extends Controller
{
    /**
     * @return array{balance: string, balance_is_negative: bool}
     */
    private static function balance(MoneyAccount $account): array
    {
        $balance = Money::ofMinor(MoneyAvailability::balanceMinor($account));

        return ['balance' => $balance->format(), 'balance_is_negative' => $balance->isCredit()] + EmployeeCash::position($account, $balance);
    }

    /**
     * ⭐ B23 (25 Oct 2026; R77): one money account's page. Its lifetime in
     * and out by the kind of document that moved it, all time unless the
     * reader narrows the window; the lines themselves are the general ledger
     * of its head, one click away.
     */
    public function show(Request $request, MoneyAccount $moneyAccount): Response
    {
        $range = LifetimeRange::fromRequest($request);
        $ledger = $moneyAccount->account_id === null ? null : Account::query()->find($moneyAccount->account_id);

        return Inertia::render('accounting/money-accounts/show', [
            'account' => [
                'id' => $moneyAccount->id,
                'name' => $moneyAccount->name,
                'type_label' => $moneyAccount->type->label(),
                'account_number' => $moneyAccount->account_number,
                'bank_name' => $moneyAccount->bank_name,
                'branch' => $moneyAccount->branch,
                'provider' => $moneyAccount->provider,
                'is_active' => $moneyAccount->is_active,
                'ledger_account' => $ledger?->label(),
                'ledger_url' => $ledger === null ? null : route('accounting.general-ledger', array_filter([
                    'account' => $ledger->id,
                    'from' => $range->statementFrom()->toDateString(),
                    'to' => $range->statementTo()->toDateString(),
                ]), false),
                ...self::balance($moneyAccount),
            ],
            'range' => $range->toArray(),
            'lifetime' => (new MoneyAccountLifetime($moneyAccount, $range))->summary(),
        ]);
    }

    /** ⭐ B23: the in-and-out by kind as a spreadsheet, for the same window. */
    public function lifetimeExport(Request $request, MoneyAccount $moneyAccount): StreamedResponse
    {
        $summary = (new MoneyAccountLifetime($moneyAccount, LifetimeRange::fromRequest($request)))->summary();
        $rows = [['Opening', '', '', '', '', $summary['opening']]];

        foreach ($summary['kinds'] as $kind) {
            $rows[] = [$kind['label'], $kind['module'], $kind['count'], $kind['money_in'], $kind['money_out'], $kind['net']];
        }

        $rows[] = ['Closing', '', '', $summary['money_in'], $summary['money_out'], $summary['closing']];

        return LifetimeCsv::download(
            'money-account-'.$moneyAccount->id.'-lifetime.csv',
            ['Kind of document', 'Area', 'How many', 'Money in', 'Money out', 'Net'],
            $rows,
        );
    }

    public function index(): Response
    {
        // ⚠ The ledger label is resolved from THIS side rather than through a
        // relation on MoneyAccount. Foundation is the base module and
        // references nothing above it - one lookup here is cheaper than
        // inverting that for an eager load.
        $ledgerAccounts = Account::query()->get()->keyBy('id');
        // ⭐ Initial plan B3: who holds each Employee cash account.
        $custodians = EmployeeCash::custodianNames();

        $accounts = MoneyAccount::query()
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return Inertia::render('accounting/money-accounts/index', [
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('money_account', $accounts, request()->user()),
            'deleteReasons' => MasterRecords::reasons(),
            'accounts' => $accounts
                ->map(fn (MoneyAccount $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'type_label' => $account->type->label(),
                    'account_number' => $account->account_number,
                    'bank_name' => $account->bank_name,
                    'branch' => $account->branch,
                    'routing_number' => $account->routing_number,
                    'overdraft_limit' => number_format(((int) $account->overdraft_limit_minor) / 100, 2, '.', ''),
                    'provider' => $account->provider,
                    'note' => $account->note,
                    'is_active' => $account->is_active,
                    'custodian_employee_id' => $account->custodian_employee_id,
                    'custodian' => $custodians[(int) $account->custodian_employee_id] ?? null,
                    'account_id' => $account->account_id,
                    'ledger_account' => $ledgerAccounts->get($account->account_id)?->label(),
                    // ⚠ Legacy rows created before the rule above can still be
                    // unmapped, and a payment from one WILL fail. The screen says
                    // so rather than waiting for the till to.
                    'is_unmapped' => $account->account_id === null,
                    // ⚠ F32: today's balance from the ledger. A courier or
                    // gateway account reads only the lines that name it.
                    ...self::balance($account),
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
            // ⭐ Initial plan B3: the HR employees who may hold company cash.
            'employees' => collect($custodians)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            'courierLedgerAccountId' => Account::bySlug('courier_receivable')?->id,
            'gatewayLedgerAccountId' => Account::bySlug('gateway_receivable')?->id,
        ]);
    }

    /**
     * ⚠⚠ A cash box, bank account or wallet gets its OWN ledger head, created
     * here by the system and never picked (21 Sep 2026, Support\MoneyLedgers):
     * BRAC Bank becomes "11201 BRAC Bank - Bor de Guna" under 1120 Bank Accounts.
     */
    public function store(Request $request): RedirectResponse
    {
        $account = DB::transaction(function () use ($request) {
            $account = MoneyAccount::create($this->validated($request));
            MoneyLedgers::ensureOwnLedger($account);

            return $account;
        });

        return back()->with('success', "{$account->label()} added, with its own ledger.");
    }

    public function update(Request $request, MoneyAccount $moneyAccount): RedirectResponse
    {
        $data = $this->validated($request);

        // ⚠ Once money has moved through an account its kind is fixed: its
        // postings sit on a Bank ledger, and calling it a wallet now would put
        // half its history in one group and the rest in another.
        if ($data['type'] !== $moneyAccount->type->value
            && DB::table('journal_lines')->where('money_account_id', $moneyAccount->id)->exists()) {
            throw ValidationException::withMessages([
                'type' => "{$moneyAccount->name} already has postings, so its kind cannot change. Deactivate it and add a new account instead.",
            ]);
        }

        DB::transaction(function () use ($moneyAccount, $data) {
            $previousHead = $moneyAccount->account_id;
            $moneyAccount->update($data);
            MoneyLedgers::ensureOwnLedger($moneyAccount);

            // Turned into a courier or gateway account before anything was
            // posted: the head it had of its own is left empty, so it goes.
            if (! MoneyLedgers::hasOwnLedger($moneyAccount->type) && $previousHead !== null) {
                $old = Account::find($previousHead);

                if ($old !== null && MoneyLedgers::isMoneyHead($old)
                    && MoneyLedgers::ownersOf($old->id)->isEmpty()
                    && ! DB::table('journal_lines')->where('account_id', $old->id)->exists()
                    && $old->system_slug === null) {
                    $old->delete();
                }
            }
        });

        return back()->with('success', "{$moneyAccount->label()} updated.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // ⚠ A courier's account reports under 1145 Receivable from Couriers and
        // nowhere else, and it must say so from the start: money a courier is
        // holding filed under Cash at Bank would overstate the bank on every
        // balance sheet until the payout arrived.
        if ($request->input('type') === MoneyAccountType::Courier->value) {
            $courierLedger = Account::bySlug('courier_receivable');

            $request->validate([
                'account_id' => ['required', Rule::in([$courierLedger?->id])],
            ], [
                'account_id.required' => 'A courier account reports under 1145 Receivable from Couriers.',
                'account_id.in' => 'A courier account reports under 1145 Receivable from Couriers.',
            ]);
        }

        // ⚠ The same rule for an online payment gateway (SSLCommerz slice P2):
        // money it has taken and not yet settled is owed by the gateway, 1146.
        if ($request->input('type') === MoneyAccountType::Gateway->value) {
            $gatewayLedger = Account::bySlug('gateway_receivable');

            $request->validate([
                'account_id' => ['required', Rule::in([$gatewayLedger?->id])],
            ], [
                'account_id.required' => 'A payment gateway account reports under 1146 Receivable from Payment Gateways.',
                'account_id.in' => 'A payment gateway account reports under 1146 Receivable from Payment Gateways.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(MoneyAccountType::class)],
            // ⚠ Everything below is optional because CASH needs none of it,
            // which is exactly why cash is the only kind that can never be
            // wrong about how somebody was paid.
            'account_number' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'routing_number' => ['nullable', 'string', 'max:255'],
            // Taka on the wire, paisa in the row (finance policy B14).
            'overdraft_limit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
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
            // ⭐ Initial plan B3 (R60): who holds an Employee cash account.
            'custodian_employee_id' => ['nullable', 'required_if:type,'.MoneyAccountType::EmployeeCash->value, 'integer', Rule::exists('employees', 'id')],
        ], [
            'custodian_employee_id.required_if' => 'Pick the employee who holds this cash.',
        ]);

        // Only an Employee cash account has a holder.
        if ($validated['type'] !== MoneyAccountType::EmployeeCash->value) {
            $validated['custodian_employee_id'] = null;
        }

        // ⚠ Cash, bank and wallets never choose their ledger: the system gives
        // each its own. Whatever the request says is ignored, not trusted.
        if (MoneyLedgers::hasOwnLedger(MoneyAccountType::from($validated['type']))) {
            unset($validated['account_id']);
        }

        // ⚠ Only a bank can hold an overdraft. A cash box or wallet cannot pay
        // money it does not have, whatever the form sent.
        $validated['overdraft_limit_minor'] = $validated['type'] === MoneyAccountType::Bank->value
            ? (int) round(((float) ($validated['overdraft_limit'] ?? 0)) * 100)
            : 0;
        unset($validated['overdraft_limit']);

        return $validated;
    }
}
