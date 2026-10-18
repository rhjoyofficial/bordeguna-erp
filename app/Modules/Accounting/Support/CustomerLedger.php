<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ ONE CUSTOMER'S LEDGER (party ledgers, 20 Oct 2026), the mirror of
 * SupplierLedger: Tally's ledger under Sundry Debtors.
 *
 * The "1140-<id> Customer" head in the chart is a NON-POSTABLE label
 * (EntityLedgers): nothing is ever posted to it. Picking it opens this VIEW,
 * which reads the customer's party-tagged lines on the real control
 * accounts: 1140 Accounts Receivable (what they were invoiced and paid),
 * 2150 Customer Advances (money they paid before an invoice) and any other
 * account a posting named them on.
 *
 * ⚠⚠ GROSS FIRST, NET SECOND, AND RECONCILED PER ACCOUNT, exactly as the
 * supplier view: each account's closing (opening plus the lines listed) is
 * compared with an independent sum of the customer's lines on it.
 *
 * ⚠ Open sales orders are OPERATIONAL (ordered, not yet invoiced), shown
 * apart from every ledger figure.
 */
final class CustomerLedger
{
    public const ROLE_RECEIVABLE = 'receivable';

    public const ROLE_ADVANCES = 'advances';

    public const ROLE_OTHER = 'other';

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  Collection<int, array<string, mixed>>  $commitments
     */
    private function __construct(
        public readonly Customer $customer,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $accounts,
        public readonly Collection $lines,
        public readonly Collection $commitments,
        /** Net owed to us at the start of the window. */
        public readonly int $openingNet,
    ) {}

    public static function for(Customer $customer, Carbon $from, Carbon $to): self
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $roles = self::roleAccounts($to);
        $partied = fn (Builder $query) => $query
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.party_type', $customer->getMorphClass())
            ->where('journal_lines.party_id', $customer->id);

        $sumByAccount = fn (callable $narrow) => $narrow($partied(JournalLine::query()))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.amount_minor) as minor')
            ->pluck('minor', 'journal_lines.account_id')
            ->map(fn ($minor) => (int) $minor);

        $opening = $sumByAccount(fn (Builder $q) => $q->whereDate('journals.journal_date', '<', $fromDate));
        $ledgerClosing = $sumByAccount(fn (Builder $q) => $q->whereDate('journals.journal_date', '<=', $toDate));

        /** @var EloquentCollection<int, JournalLine> $rows */
        $rows = $partied(JournalLine::query())
            ->whereDate('journals.journal_date', '>=', $fromDate)
            ->whereDate('journals.journal_date', '<=', $toDate)
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no')
            ->select('journal_lines.*')
            ->with(['journal', 'journal.source', 'journal.reverses:id,journal_number', 'journal.reversal:id,journal_number,reverses_journal_id'])
            ->get();

        $accountIds = collect([...$roles->keys(), ...$opening->keys(), ...$ledgerClosing->keys(), ...$rows->pluck('account_id')])
            ->map(fn ($id) => (int) $id)
            ->unique();
        $chart = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $roleOf = fn (int $accountId) => $roles->get($accountId, self::ROLE_OTHER);
        $order = [self::ROLE_RECEIVABLE => 0, self::ROLE_ADVANCES => 1, self::ROLE_OTHER => 2];

        // Running balance per account (debit-positive) and the net owed to us
        // across receivable and advances (debit-positive too: owed = positive).
        $running = $opening->all();
        $net = $opening->filter(fn ($minor, $id) => $roleOf((int) $id) !== self::ROLE_OTHER)->sum();
        $openingNet = $net;
        $vouchers = VoucherNarrations::forJournals($rows->pluck('journal_id'));

        $lines = $rows->map(function (JournalLine $line) use (&$running, &$net, $roleOf, $chart, $vouchers) {
            $accountId = (int) $line->account_id;
            $running[$accountId] = ($running[$accountId] ?? 0) + (int) $line->amount_minor;
            $role = $roleOf($accountId);

            if ($role !== self::ROLE_OTHER) {
                $net += (int) $line->amount_minor;
            }

            $balance = Money::ofMinor($running[$accountId]);
            $journal = $line->journal;
            $entry = new LedgerEntry($line, $balance, $vouchers);

            return [
                'id' => $line->id,
                'journal_id' => $journal->id,
                'journal_number' => $journal->journal_number,
                'date' => $journal->journal_date->toDateString(),
                'account_id' => $accountId,
                'account_code' => $chart->get($accountId)?->code,
                'account_name' => $chart->get($accountId)?->name,
                'role' => $role,
                'document' => self::document($journal),
                'memo' => $entry->description(),
                'reversed_by' => ($by = $journal->reversal->first()) === null ? null : ['id' => $by->id, 'number' => $by->journal_number],
                'reversal_of' => ($of = $journal->reverses) === null ? null : ['id' => $of->id, 'number' => $of->journal_number],
                'debit' => $line->isDebit() ? $line->amount()->format() : null,
                'credit' => $line->isDebit() ? null : $line->amount()->absolute()->format(),
                'balance' => $balance->absolute()->format(),
                'balance_side' => $balance->isCredit() ? 'credit' : 'debit',
                'net' => $role === self::ROLE_OTHER ? null : Money::ofMinor($net)->format(),
            ];
        })->values();

        $accounts = $accountIds
            ->map(function (int $accountId) use ($chart, $roleOf, $opening, $ledgerClosing, $rows) {
                $account = $chart->get($accountId);
                $mine = $rows->where('account_id', $accountId);
                $open = (int) ($opening[$accountId] ?? 0);
                $debits = (int) $mine->where('amount_minor', '>', 0)->sum('amount_minor');
                $credits = (int) $mine->where('amount_minor', '<', 0)->sum('amount_minor');

                return [
                    'id' => $accountId,
                    'code' => $account?->code,
                    'name' => $account?->name,
                    'role' => $roleOf($accountId),
                    'opening' => $open,
                    'debits' => $debits,
                    'credits' => $credits,
                    'closing' => $open + $debits + $credits,
                    'ledger_closing' => (int) ($ledgerClosing[$accountId] ?? 0),
                    'lines' => $mine->count(),
                ];
            })
            ->filter(fn (array $row) => $row['role'] !== self::ROLE_OTHER || $row['lines'] > 0 || $row['opening'] !== 0 || $row['ledger_closing'] !== 0)
            ->sortBy([fn ($a, $b) => $order[$a['role']] <=> $order[$b['role']], fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code'])])
            ->values();

        return new self($customer, $from, $to, $accounts, $lines, self::openOrders($customer), $openingNet);
    }

    /** The customer whose label head this account is, or null (another business's stays null). */
    public static function customerForHead(Account $account): ?Customer
    {
        if ($account->is_postable) {
            return null;
        }

        return Customer::query()->where('account_id', $account->id)->first();
    }

    public function balanceOf(string $role): int
    {
        return (int) $this->accounts->where('role', $role)->sum('closing');
    }

    /** Invoiced and not yet received (owed to us = positive). */
    public function receivable(): Money
    {
        return Money::ofMinor($this->balanceOf(self::ROLE_RECEIVABLE));
    }

    /** Paid to us ahead of an invoice and not yet used (we hold it = positive). */
    public function advances(): Money
    {
        return Money::ofMinor(-$this->balanceOf(self::ROLE_ADVANCES));
    }

    /** Management view only: receivable less advances held. Never posted. */
    public function net(): Money
    {
        return $this->receivable()->plus($this->advances()->negated());
    }

    public function isConsistent(): bool
    {
        return $this->accounts->every(fn (array $row) => $row['closing'] === $row['ledger_closing']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $signed = fn (Money $money) => ['amount' => $money->absolute()->format(), 'negative' => $money->isCredit()];
        $side = fn (int $minor) => Money::ofMinor($minor)->isCredit() ? 'credit' : 'debit';

        return [
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'code' => $this->customer->code,
                'head' => $this->customer->account_id === null ? null : Account::query()->find($this->customer->account_id)?->label(),
            ],
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'cards' => [
                'receivable' => $signed($this->receivable()),
                'advances' => $signed($this->advances()),
                'net' => $signed($this->net()),
                'open_orders' => Money::sum($this->commitments->map(fn (array $order) => Money::ofMinor($order['open_minor'])))->format(),
            ],
            'accounts' => $this->accounts->map(fn (array $row) => [
                'id' => $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'role' => $row['role'],
                'opening' => Money::ofMinor($row['opening'])->absolute()->format(),
                'opening_side' => $side($row['opening']),
                'debits' => Money::ofMinor($row['debits'])->format(),
                'credits' => Money::ofMinor($row['credits'])->absolute()->format(),
                'closing' => Money::ofMinor($row['closing'])->absolute()->format(),
                'closing_side' => $side($row['closing']),
                'ledger_closing' => Money::ofMinor($row['ledger_closing'])->absolute()->format(),
                'ledger_closing_side' => $side($row['ledger_closing']),
                'consistent' => $row['closing'] === $row['ledger_closing'],
                'lines' => $row['lines'],
            ])->values()->all(),
            'opening_net' => Money::ofMinor($this->openingNet)->format(),
            'lines' => $this->lines->all(),
            'commitments' => $this->commitments->map(fn (array $order) => collect($order)->except('open_minor')->all())->values()->all(),
            'consistent' => $this->isConsistent(),
        ];
    }

    /** @return Collection<int, string> account id => role */
    private static function roleAccounts(Carbon $on): Collection
    {
        $receivable = AccountMapping::forKeyOn('sales.accounts_receivable', $on)?->account ?? Account::bySlug('accounts_receivable');
        $advances = Account::bySlug('customer_advances');

        return collect([[$receivable, self::ROLE_RECEIVABLE], [$advances, self::ROLE_ADVANCES]])
            ->filter(fn (array $pair) => $pair[0] !== null)
            ->mapWithKeys(fn (array $pair) => [(int) $pair[0]->id => $pair[1]]);
    }

    /** @return array{label: string, number: string|null, url: string|null} */
    public static function document(Journal $journal): array
    {
        $source = $journal->source;

        $label = match (true) {
            $journal->journal_type === JournalType::OpeningBalance => JournalSourceCatalog::OPENING,
            $source instanceof Voucher => $source->type->label(),
            default => JournalSourceCatalog::label($journal->source_type) ?? JournalSourceCatalog::MANUAL,
        };

        if ($journal->reverses_journal_id !== null) {
            $label .= ' (reversal)';
        }

        return [
            'label' => $label,
            'number' => JournalSourceCatalog::documentNumber($source),
            'url' => self::documentUrl($source),
        ];
    }

    private static function documentUrl(?Model $source): ?string
    {
        return match (true) {
            $source instanceof SalesInvoice => self::url('sales.invoices.show', $source->id),
            $source instanceof SalesReturn => self::url('sales.returns.show', $source->id),
            $source instanceof SalesOrder => self::url('sales.orders.show', $source->id),
            $source instanceof Voucher => self::url('accounting.vouchers.show', $source->id),
            default => null,
        };
    }

    private static function url(string $name, int $id): ?string
    {
        return Route::has($name) ? route($name, $id, false) : null;
    }

    /**
     * Orders still being delivered: a commitment, not a receivable, with
     * what is still to pay on each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function openOrders(Customer $customer): Collection
    {
        return SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->orderBy('id')
            ->get()
            ->map(function (SalesOrder $order) {
                $open = $order->stillToPay();

                return [
                    'id' => $order->id,
                    'number' => $order->order_number,
                    'order_date' => $order->created_at?->toDateString(),
                    'status' => $order->status->value,
                    'open' => $open->format(),
                    'open_minor' => $open->minor,
                    'url' => self::url('sales.orders.show', $order->id),
                ];
            })
            ->values();
    }
}
