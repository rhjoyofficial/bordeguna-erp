<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\SaveCounterparty;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\CounterpartyBalances;
use App\Modules\Accounting\Support\CounterpartyStatement;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Accounting\Support\ReportDates;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting > Counterparties (loans plan v2, phase 1, 14 Oct 2026): the banks,
 * lenders and borrowers every loan line names, with what each owes or is owed
 * on each loan head, a statement and a balance confirmation letter.
 *
 * ⚠ Reading is `accounting.counterparties.view` (Owner, Finance, Manager);
 * changing is `.manage` (Owner, Finance). Both on the routes.
 *
 * ⚠ The counterparty is group-wide; every balance here is THIS business's books
 * (CounterpartyBalances), and the page says so.
 *
 * ⚠ store and update answer JSON: the same dialog serves this screen and the
 * "New counterparty" row inside a voucher's picker, where an Inertia redirect
 * would re-render the half-filled voucher underneath it.
 */
class CounterpartyController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(CounterpartyKind::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);
        $status = $filters['status'] ?? 'active';

        $counterparties = Counterparty::query()
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('legal_name', 'like', $like)
                    ->orWhere('tin', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('registration_no', 'like', $like));
            })
            ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('legal_name')
            ->get();

        $heads = CounterpartyBalances::heads();
        $balances = CounterpartyBalances::minor(null, $counterparties->pluck('id')->all());

        return Inertia::render('accounting/counterparties/index', [
            'filters' => ['search' => $filters['search'] ?? '', 'kind' => $filters['kind'] ?? '', 'status' => $status],
            'counterparties' => $counterparties->map(fn (Counterparty $counterparty) => [
                'id' => $counterparty->id,
                'legal_name' => $counterparty->legal_name,
                'kind' => $counterparty->kind->value,
                'related_party' => $counterparty->related_party,
                'tin' => $counterparty->tin,
                'phone' => $counterparty->phone,
                'is_active' => $counterparty->is_active,
                ...$this->balanceRows($heads, $balances[$counterparty->id] ?? []),
            ])->values(),
            'heads' => $this->headTotals($heads),
            'kinds' => $this->kinds(),
            'canManage' => (bool) $request->user()?->can('accounting.counterparties.manage'),
            'linkOptions' => Inertia::optional(fn () => $this->linkOptions()),
        ]);
    }

    public function show(Request $request, Counterparty $counterparty): Response
    {
        $counterparty->load(['customer', 'supplier', 'employee', 'createdBy:id,name', 'updatedBy:id,name']);
        $rows = $this->balanceRows(CounterpartyBalances::heads(), CounterpartyBalances::minor(null, [$counterparty->id])[$counterparty->id] ?? []);

        return Inertia::render('accounting/counterparties/show', [
            'counterparty' => [
                'id' => $counterparty->id,
                'legal_name' => $counterparty->legal_name,
                'kind' => $counterparty->kind->value,
                'related_party' => $counterparty->related_party,
                'relationship' => $counterparty->relationship,
                'tin' => $counterparty->tin,
                'bin' => $counterparty->bin,
                'registration_no' => $counterparty->registration_no,
                'phone' => $counterparty->phone,
                'email' => $counterparty->email,
                'address' => $counterparty->address,
                'bank_details' => $counterparty->bank_details,
                'notes' => $counterparty->notes,
                'is_active' => $counterparty->is_active,
                'customer_id' => $counterparty->customer_id,
                'supplier_id' => $counterparty->supplier_id,
                'employee_id' => $counterparty->employee_id,
                'customer' => $counterparty->customer === null ? null : ['id' => $counterparty->customer->id, 'label' => $this->withBusiness($counterparty->customer->name, (int) $counterparty->customer->business_id)],
                'supplier' => $counterparty->supplier === null ? null : ['id' => $counterparty->supplier->id, 'label' => $this->withBusiness($counterparty->supplier->name, (int) $counterparty->supplier->business_id)],
                'employee' => $counterparty->employee === null ? null : ['id' => $counterparty->employee->id, 'label' => PartyLedgers::label($counterparty->employee).' ('.$counterparty->employee->employee_code.')'],
                'created_by' => $counterparty->createdBy?->name,
                'created_at' => $counterparty->created_at?->toDateString(),
                'updated_by' => $counterparty->updatedBy?->name,
                'updated_at' => $counterparty->updated_at?->toDateString(),
            ],
            'balances' => $rows['balances'],
            'totals' => ['owed_to_us' => $rows['owed_to_us'], 'we_owe' => $rows['we_owe']],
            'business' => $this->businessName(),
            'kinds' => $this->kinds(),
            'canManage' => (bool) $request->user()?->can('accounting.counterparties.manage'),
            'linkOptions' => Inertia::optional(fn () => $this->linkOptions()),
        ]);
    }

    public function store(Request $request, SaveCounterparty $save): JsonResponse
    {
        $counterparty = $save->handle($request->all(), $request->user());

        return response()->json($this->saved($counterparty), 201);
    }

    public function update(Request $request, Counterparty $counterparty, SaveCounterparty $save): JsonResponse
    {
        $counterparty = $save->handle($request->all(), $request->user(), $counterparty);

        return response()->json($this->saved($counterparty));
    }

    public function deactivate(Request $request, Counterparty $counterparty, SaveCounterparty $save): RedirectResponse
    {
        $save->deactivate($counterparty, $request->user());

        return back()->with('success', "{$counterparty->legal_name} is switched off. Its history stays.");
    }

    public function activate(Request $request, Counterparty $counterparty, SaveCounterparty $save): RedirectResponse
    {
        $save->activate($counterparty, $request->user());

        return back()->with('success', "{$counterparty->legal_name} is back on the pickers.");
    }

    /**
     * One counterparty's ledgers per loan head, with a running balance. Fiscal
     * year to date by default, like every accounting report. Printed from the
     * browser like the other statements.
     */
    public function statement(Request $request, Counterparty $counterparty): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());
        [$from, $to] = $this->window($request, $business);

        $statement = CounterpartyStatement::for($counterparty, $from, $to);

        return Inertia::render('accounting/counterparties/statement', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'counterparty' => ['id' => $counterparty->id, 'legal_name' => $counterparty->legal_name, 'address' => $counterparty->address],
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'heads' => $statement->heads->map(fn (array $head) => [
                'account' => $head['account']->label(),
                'slug' => $head['account']->system_slug,
                'type' => $head['account']->type->value,
                'opening' => $head['opening']->absolute()->format(),
                'opening_side' => $head['opening']->isCredit() ? 'credit' : 'debit',
                'debits' => $head['debits']->format(),
                'credits' => $head['credits']->format(),
                'closing' => $head['closing']->absolute()->format(),
                'closing_side' => $head['closing']->isCredit() ? 'credit' : 'debit',
                'lines' => $head['lines']->map(fn (array $line) => [
                    'date' => $line['date'],
                    'journal_number' => $line['journal_number'],
                    'journal_id' => $line['journal_id'],
                    'memo' => $line['memo'],
                    'source' => $line['source'],
                    'debit' => $line['amount']->isDebit() ? $line['amount']->format() : null,
                    'credit' => $line['amount']->isDebit() ? null : $line['amount']->absolute()->format(),
                    'balance' => $line['balance']->absolute()->format(),
                    'balance_side' => $line['balance']->isCredit() ? 'credit' : 'debit',
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * The balance confirmation letter (ChatGPT review, point 6: counterparty
     * statements and confirmations): what our books say each side owes as at a
     * date, on the letterhead, for the counterparty to sign and return.
     */
    public function confirmation(Request $request, Counterparty $counterparty): Response
    {
        $asAt = $request->filled('as_at') ? ReportDates::parse($request, 'as_at') : Carbon::today();
        $heads = CounterpartyBalances::heads();

        return Inertia::render('accounting/counterparties/confirmation', [
            'as_at' => $asAt->toDateString(),
            'counterparty' => ['id' => $counterparty->id, 'legal_name' => $counterparty->legal_name, 'address' => $counterparty->address],
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'balances' => collect(CounterpartyBalances::of($counterparty, $asAt))
                ->map(fn (int $minor, string $slug) => [
                    'label' => $heads[$slug]->label(),
                    'amount' => Money::ofMinor($minor)->absolute()->format(),
                    // A debit is owed TO the business, whichever head it sits on.
                    'owed_by' => $minor > 0 ? 'counterparty' : 'business',
                ])
                ->values(),
        ]);
    }

    /**
     * Each non-zero balance with its side, and the two totals a person reads
     * first: what the counterparty owes the business, what the business owes it.
     *
     * @param  Collection<string, Account>  $heads
     * @param  array<string, int>  $minor  slug => signed paisa
     * @return array{balances: list<array{slug: string, label: string, amount: string, side: string}>, owed_to_us: string, we_owe: string}
     */
    private function balanceRows($heads, array $minor): array
    {
        $rows = [];
        $owedToUs = 0;
        $weOwe = 0;

        foreach ($heads as $slug => $account) {
            $amount = $minor[$slug] ?? 0;

            if ($amount === 0) {
                continue;
            }

            $rows[] = ['slug' => $slug, 'label' => $account->label(), 'amount' => Money::ofMinor($amount)->absolute()->format(), 'side' => $amount > 0 ? 'debit' : 'credit'];

            if ($account->type === AccountType::Asset) {
                $owedToUs += $amount;
            } else {
                $weOwe -= $amount;
            }
        }

        return ['balances' => $rows, 'owed_to_us' => Money::ofMinor($owedToUs)->format(), 'we_owe' => Money::ofMinor($weOwe)->format()];
    }

    /**
     * Each loan head with postings: the counterparties' total beside the
     * ledger's own balance. They agree, or the Reconciliation screen says why.
     *
     * @param  Collection<string, Account>  $heads
     * @return list<array{slug: string, label: string, counterparties_total: string, ledger_total: string, side: string, agrees: bool}>
     */
    private function headTotals($heads): array
    {
        $all = CounterpartyBalances::minor();
        $rows = [];

        foreach ($heads as $slug => $account) {
            $ledger = (int) JournalLine::query()->where('account_id', $account->id)->sum('amount_minor');
            $parties = (int) array_sum(array_map(fn (array $balances) => $balances[$slug] ?? 0, $all));

            if ($ledger === 0 && $parties === 0 && JournalLine::query()->where('account_id', $account->id)->doesntExist()) {
                continue;
            }

            $rows[] = [
                'slug' => $slug,
                'label' => $account->label(),
                'counterparties_total' => Money::ofMinor($parties)->absolute()->format(),
                'ledger_total' => Money::ofMinor($ledger)->absolute()->format(),
                'side' => $ledger < 0 ? 'credit' : 'debit',
                'agrees' => $ledger === $parties,
            ];
        }

        return $rows;
    }

    /** @return array{id: int, label: string, related: bool, url: string} */
    private function saved(Counterparty $counterparty): array
    {
        return [
            'id' => $counterparty->id,
            'label' => $counterparty->legal_name,
            'related' => $counterparty->related_party,
            'url' => route('accounting.counterparties.show', $counterparty),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function kinds(): array
    {
        return array_map(fn (CounterpartyKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], CounterpartyKind::cases());
    }

    /**
     * The customers, suppliers and employees a counterparty may be linked to:
     * THIS business's customers and suppliers (they are per business), every
     * employee (central). Loaded only when the edit dialog opens.
     *
     * @return array<string, list<array{id: int, label: string}>>
     */
    private function linkOptions(): array
    {
        return [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name])->all(),
            'employees' => Employee::query()->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => PartyLedgers::label($e).' ('.$e->employee_code.')'])->all(),
        ];
    }

    private function withBusiness(string $name, int $businessId): string
    {
        if ($businessId === app(BusinessContext::class)->id()) {
            return $name;
        }

        return $name.' - '.(Business::withoutGlobalScopes()->find($businessId)?->name ?? '');
    }

    private function businessName(): string
    {
        return (string) Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id())?->name;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws ValidationException
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();
        $yearStart = $business === null ? $today->copy()->startOfYear() : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? ReportDates::parse($request, 'from') : $yearStart;
        $to = $request->filled('to') ? ReportDates::parse($request, 'to') : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
