<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a supplier still holds of our money in 1160 Advances to Suppliers, as
 * documents (ruling P2, 18 Oct 2026).
 *
 * Two kinds of open advance:
 *
 *   1. ADVANCE DOCUMENTS (SupplierAdvance): paid from Purchases > Payments, an
 *      overpayment kept as an advance, or an opening advance. Each knows its
 *      own open balance: amount less live applications and refunds.
 *
 *   2. THE ON-ACCOUNT BALANCE: 1160 lines with the supplier as party that NO
 *      advance document posted. Before 19 Oct 2026 a Payment voucher on a
 *      supplier sent whatever no bill was waiting for to 1160 (PostVoucher::
 *      personLines), and a voucher or journal voucher may still name 1160
 *      directly. Those lines are real advances with no document behind them.
 *
 * ⭐ WHY A POOL AND NOT A BACKFILLED DOCUMENT PER VOUCHER LINE. A 1160 line
 * from a voucher or journal can be a credit too (a refund received on a
 * Receipt voucher, a correcting journal), and a reversed voucher posts a
 * mirror line. Netting all of them per supplier, straight from the ledger, is
 * right whatever mix of debits and credits exists, needs no data migration and
 * no change to how vouchers post, and keeps working for every voucher posted
 * after this. Applications and refunds drawn from it are documents of their
 * own (supplier_advance_id NULL) with the vouchers they drew on named in
 * `source_note`, oldest first.
 *
 * ⚠⚠ The identity this keeps, per supplier, at any date:
 *
 *   GL 1160 (party = supplier) = Σ advance documents' open
 *                              + on-account balance
 *
 * because every journal an advance document, an application, a refund or an
 * overpayment payment posts is left out of the on-account sum (OWN_SOURCES)
 * and counted through its document instead.
 */
final class SupplierAdvanceBook
{
    /** The documents whose 1160 lines are counted through the documents themselves. */
    private const OWN_SOURCES = [
        SupplierAdvance::class,
        SupplierAdvanceApplication::class,
        SupplierAdvanceRefund::class,
        SupplierPayment::class,
    ];

    public static function account(): ?Account
    {
        return Account::bySlug('supplier_advances');
    }

    /**
     * Live advance documents of this supplier with something still open,
     * oldest first, each with `open` as Money.
     *
     * @return Collection<int, array{advance: SupplierAdvance, open: Money}>
     */
    public static function openAdvances(Supplier $supplier): Collection
    {
        return SupplierAdvance::query()
            ->where('supplier_id', $supplier->id)
            ->live()
            ->with(['purchaseOrder:id,po_number'])
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get()
            ->map(fn (SupplierAdvance $advance) => ['advance' => $advance, 'open' => $advance->open()])
            ->filter(fn (array $row) => $row['open']->minor > 0)
            ->values();
    }

    /**
     * The supplier's on-account 1160 balance: what vouchers and journals put
     * there without an advance document, less what has been applied or
     * refunded from it. Negative only if those lines were credited away after
     * the balance was used, which is a finding for Finance, not a figure to
     * hide.
     */
    public static function onAccount(Supplier $supplier, ?Carbon $asAt = null): Money
    {
        $lots = self::onAccountLots($supplier, $asAt);

        return $lots['balance'];
    }

    /**
     * The on-account balance as LOTS: the debits it came from, oldest first,
     * with what is left of each after every credit and every application or
     * refund drawn from it (first in, first out). The lots date the ageing
     * and name the vouchers an application drew on.
     *
     * @return array{balance: Money, lots: list<array{date: string, reference: string, open: Money}>}
     */
    public static function onAccountLots(Supplier $supplier, ?Carbon $asAt = null): array
    {
        $account = self::account();

        if ($account === null) {
            return ['balance' => Money::zero(), 'lots' => []];
        }

        $lines = JournalLine::query()
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.party_type', $supplier->getMorphClass())
            ->where('journal_lines.party_id', $supplier->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where(fn ($q) => $q->whereNull('journals.source_type')->orWhereNotIn('journals.source_type', self::ownMorphs()))
            ->when($asAt, fn ($q) => $q->whereDate('journals.journal_date', '<=', $asAt->toDateString()))
            ->orderBy('journals.journal_date')
            ->orderBy('journal_lines.id')
            ->get(['journal_lines.amount_minor', 'journals.journal_date', 'journals.journal_number', 'journals.memo']);

        $drawn = self::drawnFromOnAccount($supplier, $asAt);

        $lots = [];
        $credits = $drawn->minor;

        foreach ($lines as $line) {
            $amount = (int) $line->amount_minor;

            if ($amount > 0) {
                $lots[] = [
                    'date' => Carbon::parse($line->journal_date)->toDateString(),
                    'reference' => (string) $line->journal_number,
                    'open' => $amount,
                ];
            } else {
                $credits += -$amount;
            }
        }

        $balance = array_sum(array_column($lots, 'open')) - $credits;

        // First in, first out: the oldest debit is used up first.
        foreach ($lots as $index => $lot) {
            $take = min($lot['open'], $credits);
            $lots[$index]['open'] -= $take;
            $credits -= $take;
        }

        $open = array_values(array_filter($lots, fn (array $lot) => $lot['open'] > 0));

        return [
            'balance' => Money::ofMinor($balance),
            'lots' => array_map(fn (array $lot) => [...$lot, 'open' => Money::ofMinor($lot['open'])], $open),
        ];
    }

    /**
     * The open advances of a supplier as the screens offer them: every advance
     * document with something open, then the on-account balance as one row
     * (`advance` null), each dated so the proposal can order them.
     *
     * @return Collection<int, array{advance: SupplierAdvance|null, open: Money, date: string, number: string, purchase_order_id: int|null, po_number: string|null}>
     */
    public static function available(Supplier $supplier): Collection
    {
        $rows = self::openAdvances($supplier)->map(fn (array $row) => [
            'advance' => $row['advance'],
            'open' => $row['open'],
            'date' => $row['advance']->paid_on->toDateString(),
            'number' => $row['advance']->number,
            'purchase_order_id' => $row['advance']->purchase_order_id,
            'po_number' => $row['advance']->purchaseOrder?->po_number,
        ]);

        $pool = self::onAccountLots($supplier);

        if ($pool['balance']->minor > 0 && $pool['lots'] !== []) {
            $rows->push([
                'advance' => null,
                'open' => $pool['balance'],
                'date' => $pool['lots'][0]['date'],
                'number' => 'On account',
                'purchase_order_id' => null,
                'po_number' => null,
            ]);
        }

        return $rows->values();
    }

    /**
     * ⭐ The default proposal for a bill (ruling P2): advances linked to the
     * bill's purchase order first, then every other open advance of the
     * supplier oldest first (the on-account balance by its oldest voucher),
     * until the bill is covered. Finance may change it before saving; this
     * only fills the boxes.
     *
     * @return list<array{key: string, advance_id: int|null, amount: Money}>
     */
    public static function proposalFor(SupplierBill $bill, ?Money $outstanding = null): array
    {
        $left = ($outstanding ?? $bill->outstanding())->minor;
        $proposal = [];

        foreach (self::ordered($bill) as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $row['open']->minor);
            $left -= $take;
            $proposal[] = [
                'key' => self::keyOf($row['advance']),
                'advance_id' => $row['advance']?->id,
                'amount' => Money::ofMinor($take),
            ];
        }

        return $proposal;
    }

    /**
     * The supplier's open advances in proposal order for this bill.
     *
     * @return Collection<int, array{advance: SupplierAdvance|null, open: Money, date: string, number: string, purchase_order_id: int|null, po_number: string|null}>
     */
    public static function ordered(SupplierBill $bill): Collection
    {
        $bill->loadMissing('supplier');

        return self::orderedFor($bill->supplier, $bill->purchase_order_id);
    }

    /**
     * The same order before the bill exists (the bill form): the purchase
     * order's own advances first, then the rest oldest first.
     *
     * @return Collection<int, array{advance: SupplierAdvance|null, open: Money, date: string, number: string, purchase_order_id: int|null, po_number: string|null}>
     */
    public static function orderedFor(Supplier $supplier, ?int $purchaseOrderId): Collection
    {
        return self::available($supplier)
            ->sortBy(fn (array $row) => [
                $purchaseOrderId !== null && $row['purchase_order_id'] === $purchaseOrderId ? 0 : 1,
                $row['date'],
                $row['advance']?->id ?? PHP_INT_MAX,
            ])
            ->values();
    }

    /**
     * The purchase order's card (ruling P2, test 9): what was paid in advance
     * against this order, how much of it bills have used, and what is open.
     *
     * @return array{paid: string, applied: string, refunded: string, open: string, advances: list<array<string, mixed>>}
     */
    public static function forPurchaseOrder(PurchaseOrder $order): array
    {
        $advances = SupplierAdvance::query()->where('purchase_order_id', $order->id)->live()
            ->orderBy('paid_on')->orderBy('id')->get();

        $paid = Money::sum($advances->map(fn (SupplierAdvance $a) => $a->money()));
        $applied = Money::sum($advances->map(fn (SupplierAdvance $a) => $a->appliedTotal()));
        $refunded = Money::sum($advances->map(fn (SupplierAdvance $a) => $a->refundedTotal()));

        return [
            'paid' => $paid->toTaka(),
            'applied' => $applied->toTaka(),
            'refunded' => $refunded->toTaka(),
            'open' => Money::ofMinor($paid->minor - $applied->minor - $refunded->minor)->toTaka(),
            'advances' => $advances->map(fn (SupplierAdvance $a) => [
                'id' => $a->id,
                'number' => $a->number,
                'paid_on' => $a->paid_on->toDateString(),
                'amount' => $a->money()->toTaka(),
                'open' => $a->open()->toTaka(),
            ])->values()->all(),
        ];
    }

    /** What a supplier holds of ours in total: open advance documents plus the on-account balance. */
    public static function totalOpen(Supplier $supplier): Money
    {
        return Money::sum(self::openAdvances($supplier)->map(fn (array $row) => $row['open']))
            ->plus(self::onAccount($supplier));
    }

    /** "advance:12", or "on_account" for the voucher balance: how a form names a row. */
    public static function keyOf(?SupplierAdvance $advance): string
    {
        return $advance === null ? 'on_account' : "advance:{$advance->id}";
    }

    /**
     * The vouchers or journals an on-account draw of `$amount` reaches,
     * oldest first: what an application or refund names as its source.
     */
    public static function onAccountSourceNote(Supplier $supplier, Money $amount): string
    {
        $left = $amount->minor;
        $references = [];

        foreach (self::onAccountLots($supplier)['lots'] as $lot) {
            if ($left <= 0) {
                break;
            }

            $references[] = $lot['reference'];
            $left -= $lot['open']->minor;
        }

        return mb_substr('On account from '.implode(', ', $references), 0, 255);
    }

    /**
     * ⚠⚠ A voucher or journal whose 1160 line has already been applied to a
     * bill (or refunded) cannot be reversed underneath it: the bill would stay
     * settled by an advance the ledger no longer holds. Asked by ReverseJournal
     * for every reversal; journals of the advance documents themselves are
     * guarded by their own reversals and skipped here.
     *
     * @throws ValidationException
     */
    public static function assertReversalKeepsOnAccount(Journal $journal): void
    {
        $account = self::account();

        if ($account === null || ($journal->source_type !== null && in_array($journal->source_type, self::ownMorphs(), true))) {
            return;
        }

        $supplierMorph = (new Supplier)->getMorphClass();
        $removed = [];

        foreach ($journal->lines as $line) {
            if ((int) $line->account_id === $account->id && $line->party_type === $supplierMorph) {
                $removed[(int) $line->party_id] = ($removed[(int) $line->party_id] ?? 0) + (int) $line->amount_minor;
            }
        }

        foreach ($removed as $supplierId => $minor) {
            $supplier = Supplier::query()->find($supplierId);

            if ($supplier === null || $minor <= 0) {
                continue;
            }

            $balance = self::onAccount($supplier);

            if ($balance->minor - $minor < 0) {
                throw ValidationException::withMessages([
                    'reason' => "This entry paid {$supplier->name} an advance that has since been applied to a bill or refunded. Reverse that application or refund first (".Money::ofMinor($minor - max(0, $balance->minor))->format().' of it is in use).',
                ]);
            }
        }
    }

    /** @return list<string> */
    private static function ownMorphs(): array
    {
        return array_map(fn (string $class) => (new $class)->getMorphClass(), self::OWN_SOURCES);
    }

    /** What applications and refunds have drawn from the on-account balance (live ones, or standing on `$asAt`). */
    private static function drawnFromOnAccount(Supplier $supplier, ?Carbon $asAt): Money
    {
        $scope = function (Builder $query, string $dateColumn) use ($supplier, $asAt): Builder {
            $query->where('supplier_id', $supplier->id)->whereNull('supplier_advance_id');

            return $asAt === null
                ? $query->live()
                : $query->whereDate($dateColumn, '<=', $asAt->toDateString())->standingOn($asAt->toDateString());
        };

        return Money::sum([
            Money::ofTaka((string) ($scope(SupplierAdvanceApplication::query(), 'applied_on')->sum('amount') ?: '0')),
            Money::ofTaka((string) ($scope(SupplierAdvanceRefund::query(), 'refunded_on')->sum('amount') ?: '0')),
        ]);
    }
}
