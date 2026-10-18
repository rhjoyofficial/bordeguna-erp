<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Support\Documents\DocumentLinks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ ONE SUPPLIER'S LEDGER, ACROSS EVERY CONTROL ACCOUNT THAT NAMES THEM (A1,
 * ChatGPT ruling P1, 18 Oct 2026).
 *
 * The "2110-<id> Supplier" head in the chart is a NON-POSTABLE label
 * (EntityLedgers): nothing is ever posted to it. Picking it now opens this
 * VIEW, which reads the supplier's party-tagged lines on the real control
 * accounts: 2110 Accounts Payable (what has been billed), 2120 Goods Received
 * Not Invoiced (received from them, not billed yet), 1160 Advances to
 * Suppliers (paid before a bill), and any other account a posting named them
 * on. It is a party filter over the ledger, never a balance of its own.
 *
 * ⚠⚠ GROSS FIRST, NET SECOND. Billed AP, unbilled receipts and advances are
 * shown separately; net exposure is a management figure beside them, never
 * an offset posted anywhere (a net alone can hide a large payable and a large
 * advance cancelling each other).
 *
 * ⚠⚠ RECONCILES PER ACCOUNT, NOT IN TOTAL. Each section's closing (opening
 * plus the lines listed) is compared with an independent sum of the
 * supplier's lines on that account, so a dropped line shows as a break on the
 * account it belongs to. The combined figure ties to nothing on its own:
 * advances sit in 1160 and unbilled receipts in 2120, not in 2110.
 *
 * ⚠ Labels come from the journal's SOURCE document (JournalSourceCatalog),
 * with a readable fallback, so documents added later (advance applications,
 * refunds, discounts) appear here with no change to this class.
 *
 * ⚠ Open purchase orders are OPERATIONAL: ordered and not yet received, a
 * commitment rather than a liability, shown apart from every GL figure.
 */
final class SupplierLedger
{
    public const ROLE_PAYABLE = 'payable';

    public const ROLE_GRNI = 'grni';

    public const ROLE_ADVANCES = 'advances';

    public const ROLE_OTHER = 'other';

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts  one per account, in display order
     * @param  Collection<int, array<string, mixed>>  $lines  the window's lines, in ledger order
     * @param  Collection<int, array<string, mixed>>  $commitments  open purchase orders
     */
    private function __construct(
        public readonly Supplier $supplier,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $accounts,
        public readonly Collection $lines,
        public readonly Collection $commitments,
        public readonly Money $untaggedGrni,
        public readonly int $untaggedGrniLines,
        /** Net exposure at the start of the window, owed-positive. */
        public readonly int $openingExposure,
    ) {}

    public static function for(Supplier $supplier, Carbon $from, Carbon $to): self
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $roles = self::roleAccounts($to);
        $partied = fn (Builder $query) => $query
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.party_type', $supplier->getMorphClass())
            ->where('journal_lines.party_id', $supplier->id);

        $sumByAccount = fn (callable $narrow) => $narrow($partied(JournalLine::query()))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.amount_minor) as minor')
            ->pluck('minor', 'journal_lines.account_id')
            ->map(fn ($minor) => (int) $minor);

        // ⚠ Date-string comparisons throughout (C-CST-5), as every statement.
        $opening = $sumByAccount(fn (Builder $q) => $q->whereDate('journals.journal_date', '<', $fromDate));
        // The independent check: one sum per account to the end of the window,
        // not derived from the lines below (the C-GLA-4 stance).
        $ledgerClosing = $sumByAccount(fn (Builder $q) => $q->whereDate('journals.journal_date', '<=', $toDate));

        /** @var EloquentCollection<int, JournalLine> $rows */
        $rows = $partied(JournalLine::query())
            ->whereDate('journals.journal_date', '>=', $fromDate)
            ->whereDate('journals.journal_date', '<=', $toDate)
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no')
            ->select('journal_lines.*')
            ->with([
                'journal',
                'journal.source',
                'journal.reverses:id,journal_number',
                'journal.reversal:id,journal_number,reverses_journal_id',
            ])
            ->get();

        $rows->loadMorph('journal.source', [
            GoodsReceipt::class => ['purchaseOrder:id,po_number'],
            SupplierBill::class => ['purchaseOrder:id,po_number'],
            PurchaseReturn::class => ['purchaseOrder:id,po_number'],
            SupplierCreditNote::class => ['purchaseReturn.purchaseOrder:id,po_number'],
        ]);

        $accountIds = collect([...$roles->keys(), ...$opening->keys(), ...$ledgerClosing->keys(), ...$rows->pluck('account_id')])
            ->map(fn ($id) => (int) $id)
            ->unique();
        $chart = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $roleOf = fn (int $accountId) => $roles->get($accountId, self::ROLE_OTHER);
        $order = [self::ROLE_PAYABLE => 0, self::ROLE_GRNI => 1, self::ROLE_ADVANCES => 2, self::ROLE_OTHER => 3];

        // The running balance per account (debit-positive, as the ledger
        // stores it) and the combined net exposure across the three
        // supplier-relationship accounts (owed-positive: minus the ledger sum).
        $running = $opening->all();
        $exposure = -$opening->filter(fn ($minor, $id) => $roleOf((int) $id) !== self::ROLE_OTHER)->sum();
        $vouchers = VoucherNarrations::forJournals($rows->pluck('journal_id'));

        $lines = $rows->map(function (JournalLine $line) use (&$running, &$exposure, $roleOf, $chart, $vouchers) {
            $accountId = (int) $line->account_id;
            $running[$accountId] = ($running[$accountId] ?? 0) + (int) $line->amount_minor;
            $role = $roleOf($accountId);

            if ($role !== self::ROLE_OTHER) {
                $exposure -= (int) $line->amount_minor;
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
                ...self::references($journal->source),
                'memo' => $entry->description(),
                'reversed_by' => ($by = $journal->reversal->first()) === null ? null : ['id' => $by->id, 'number' => $by->journal_number],
                'reversal_of' => ($of = $journal->reverses) === null ? null : ['id' => $of->id, 'number' => $of->journal_number],
                'debit' => $line->isDebit() ? $line->amount()->format() : null,
                'credit' => $line->isDebit() ? null : $line->amount()->absolute()->format(),
                'balance' => $balance->absolute()->format(),
                'balance_side' => $balance->isCredit() ? 'credit' : 'debit',
                'exposure' => $role === self::ROLE_OTHER ? null : Money::ofMinor($exposure)->format(),
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
            // The three relationship accounts always show, even at zero:
            // "no advance with this supplier" is an answer. Others only when
            // the supplier is on them.
            ->filter(fn (array $row) => $row['role'] !== self::ROLE_OTHER || $row['lines'] > 0 || $row['opening'] !== 0 || $row['ledger_closing'] !== 0)
            ->sortBy([fn ($a, $b) => $order[$a['role']] <=> $order[$b['role']], fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code'])])
            ->values();

        $untagged = UntaggedGrni::rows()->where('supplier_id', $supplier->id);

        return new self(
            supplier: $supplier,
            from: $from,
            to: $to,
            accounts: $accounts,
            lines: $lines,
            commitments: self::openOrders($supplier),
            untaggedGrni: Money::sum($untagged->map(fn (array $row) => Money::ofMinor($row['received_minor']))),
            untaggedGrniLines: $untagged->count(),
            openingExposure: -$opening->filter(fn ($minor, $id) => $roleOf((int) $id) !== self::ROLE_OTHER)->sum(),
        );
    }

    /**
     * The supplier whose label head this account is, or null for any other
     * account. Heads of another business's supplier stay null (audit #12).
     */
    public static function supplierForHead(Account $account): ?Supplier
    {
        if ($account->is_postable) {
            return null;
        }

        return Supplier::query()->where('account_id', $account->id)->first();
    }

    // ─── Figures ────────────────────────────────────────────────────────────

    /** The minor-unit balance of the accounts with this role, at the end of the window. */
    public function balanceOf(string $role): int
    {
        return (int) $this->accounts->where('role', $role)->sum('closing');
    }

    /** Billed AP: what the supplier has invoiced and we have not settled (owed = positive). */
    public function billedPayable(): Money
    {
        return Money::ofMinor(-$this->balanceOf(self::ROLE_PAYABLE));
    }

    /** Received from the supplier and not billed yet, from supplier-tagged GRNI. */
    public function receivedNotBilled(): Money
    {
        return Money::ofMinor(-$this->balanceOf(self::ROLE_GRNI));
    }

    /** Paid to the supplier ahead of a bill and not yet applied (an asset). */
    public function advances(): Money
    {
        return Money::ofMinor($this->balanceOf(self::ROLE_ADVANCES));
    }

    /** Management view only: billed plus unbilled, less advances. Never posted. */
    public function netExposure(): Money
    {
        return $this->billedPayable()->plus($this->receivedNotBilled())->plus($this->advances()->negated());
    }

    public function openCommitments(): Money
    {
        return Money::sum($this->commitments->map(fn (array $order) => Money::ofMinor($order['open_minor'])));
    }

    /** Every account's listed closing equals its independent party balance. */
    public function isConsistent(): bool
    {
        return $this->accounts->every(fn (array $row) => $row['closing'] === $row['ledger_closing']);
    }

    /**
     * The view as the screens receive it: every figure formatted, every side
     * named (never a bare negative).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $signed = fn (Money $money) => ['amount' => $money->absolute()->format(), 'negative' => $money->isCredit()];
        $side = fn (int $minor) => Money::ofMinor($minor)->isCredit() ? 'credit' : 'debit';

        return [
            'supplier' => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'code' => $this->supplier->code,
                'head' => $this->supplier->account_id === null ? null : Account::query()->find($this->supplier->account_id)?->label(),
            ],
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'cards' => [
                'billed_payable' => $signed($this->billedPayable()),
                'received_not_billed' => $signed($this->receivedNotBilled()),
                'advances' => $signed($this->advances()),
                'net_exposure' => $signed($this->netExposure()),
                'open_commitments' => $this->openCommitments()->format(),
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
            'opening_exposure' => Money::ofMinor($this->openingExposure)->format(),
            'lines' => $this->lines->all(),
            'commitments' => $this->commitments->map(fn (array $order) => collect($order)->except('open_minor')->all())->values()->all(),
            'untagged_grni' => [
                'amount' => $this->untaggedGrni->format(),
                'lines' => $this->untaggedGrniLines,
            ],
            'consistent' => $this->isConsistent(),
        ];
    }

    // ─── Building blocks ────────────────────────────────────────────────────

    /**
     * account id => role, for the three relationship accounts. Payable and
     * GRNI follow the posting mappings on the window's last day (what the
     * postings themselves resolve); advances is the party-kept 1160 head.
     *
     * @return Collection<int, string>
     */
    private static function roleAccounts(Carbon $on): Collection
    {
        $payable = AccountMapping::forKeyOn('purchasing.accounts_payable', $on)?->account ?? Account::bySlug('accounts_payable');
        $grni = AccountMapping::forKeyOn('inventory.goods_received_not_invoiced', $on)?->account ?? Account::bySlug('goods_received_not_invoiced');
        $advances = Account::bySlug('supplier_advances');

        return collect([
            [$payable, self::ROLE_PAYABLE],
            [$grni, self::ROLE_GRNI],
            [$advances, self::ROLE_ADVANCES],
        ])->filter(fn (array $pair) => $pair[0] !== null)
            ->mapWithKeys(fn (array $pair) => [(int) $pair[0]->id => $pair[1]]);
    }

    /**
     * What document a line came from: its kind, its own number and a link,
     * from the journal's source. A source this list does not know still gets
     * a readable kind and the journal link (the generic fallback).
     *
     * @return array{label: string, number: string|null, url: string|null}
     */
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

    /**
     * The purchase order and the supplier's challan behind a line, where the
     * source document leads to them exactly. Operational references, not
     * amounts.
     *
     * @return array{po_number: string|null, po_url: string|null, challan: string|null}
     */
    public static function references(?Model $source): array
    {
        $order = match (true) {
            $source instanceof GoodsReceipt, $source instanceof SupplierBill, $source instanceof PurchaseReturn => $source->purchaseOrder,
            $source instanceof SupplierCreditNote => $source->purchaseReturn?->purchaseOrder,
            default => null,
        };

        return [
            'po_number' => $order?->po_number,
            'po_url' => $order === null ? null : self::url('purchasing.purchase-orders.show', $order->id),
            'challan' => $source instanceof GoodsReceipt ? ($source->supplier_challan_number ?: null) : null,
        ];
    }

    private static function documentUrl(?Model $source): ?string
    {
        // PR5/integration (18 Oct 2026): one registry for every document
        // link, so a receipt or return opens its own page (PR2) and kinds
        // this list never knew (advances, discounts, journals) link too.
        return $source === null ? null : DocumentLinks::url($source);
    }

    private static function url(string $name, int $id): ?string
    {
        return Route::has($name) ? route($name, $id, false) : null;
    }

    /**
     * Approved orders still waiting for goods: what is ordered and not yet
     * received, valued the way the receipt will value it (the order line's
     * stored total), so receiving the rest moves this figure into GRNI
     * exactly.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function openOrders(Supplier $supplier): Collection
    {
        return PurchaseOrder::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', [PurchaseOrderStatus::Open, PurchaseOrderStatus::PartiallyReceived])
            ->whereHas('approvalRequest', fn (Builder $query) => $query->where('status', ApprovalStatus::Approved))
            ->with('lines')
            ->orderBy('order_date')
            ->orderBy('id')
            ->get()
            ->map(function (PurchaseOrder $order) {
                $open = Money::sum($order->lines->map(function (PurchaseOrderLine $line) {
                    $received = (string) $line->quantity_received;

                    return (float) $received <= 0.0
                        ? $line->lineTotal()
                        : $line->lineTotal()->plus($line->valueOf($received)->negated());
                })->filter(fn (Money $value) => $value->minor > 0));

                return [
                    'id' => $order->id,
                    'number' => $order->po_number,
                    'order_date' => $order->orderDateString(),
                    'status' => $order->status->value,
                    'open' => $open->format(),
                    'open_minor' => $open->minor,
                    'url' => self::url('purchasing.purchase-orders.show', $order->id),
                ];
            })
            ->filter(fn (array $order) => $order['open_minor'] > 0)
            ->values();
    }
}
