<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ What we hold of a customer's money in 2150 Advances from Customers, as
 * documents (B11, 24 Oct 2026): the mirror of SupplierAdvanceBook.
 *
 * Two kinds of open advance:
 *
 *   1. ADVANCE DOCUMENTS (CustomerAdvance): money received on account, or
 *      what a cancelled order held moved to the customer's advance. Each knows
 *      its own open balance: amount less live applications and refunds.
 *
 *   2. THE ON-ACCOUNT BALANCE: 2150 lines with the customer as party that NO
 *      advance document posted. A Receive voucher on a customer has sent
 *      whatever no invoice was waiting for to 2150 since 19 Sep 2026
 *      (PostVoucher), and a Payment voucher gives it back from there. Those
 *      lines are real advances with no document behind them, netted per
 *      customer straight from the ledger, as the supplier side does.
 *
 * ⚠⚠ The identity this keeps, per customer, at any date:
 *
 *   -GL 2150 (party = customer) = Σ advance documents' open
 *                               + on-account balance
 *
 * because every journal an advance document, an application or a refund
 * posts is left out of the on-account sum (OWN_SOURCES) and counted through
 * its document instead.
 */
final class CustomerAdvanceBook
{
    /** The documents whose 2150 lines are counted through the documents themselves. */
    private const OWN_SOURCES = [
        CustomerAdvance::class,
        CustomerAdvanceApplication::class,
        CustomerAdvanceRefund::class,
    ];

    public static function account(): ?Account
    {
        return Account::bySlug('customer_advances');
    }

    /**
     * Live advance documents of this customer with something still open,
     * oldest first, each with `open` as Money.
     *
     * @return Collection<int, array{advance: CustomerAdvance, open: Money}>
     */
    public static function openAdvances(Customer $customer): Collection
    {
        return CustomerAdvance::query()
            ->where('customer_id', $customer->id)
            ->live()
            ->orderBy('received_on')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerAdvance $advance) => ['advance' => $advance, 'open' => $advance->open()])
            ->filter(fn (array $row) => $row['open']->minor > 0)
            ->values();
    }

    /** The customer's on-account 2150 balance (what vouchers put there, less what was drawn from it). */
    public static function onAccount(Customer $customer, ?Carbon $asAt = null): Money
    {
        return self::onAccountLots($customer, $asAt)['balance'];
    }

    /**
     * The on-account balance as LOTS: the credits it came from, oldest first,
     * with what is left of each after every debit and every application or
     * refund drawn from it (first in, first out).
     *
     * @return array{balance: Money, lots: list<array{date: string, reference: string, open: Money}>}
     */
    public static function onAccountLots(Customer $customer, ?Carbon $asAt = null): array
    {
        $account = self::account();

        if ($account === null) {
            return ['balance' => Money::zero(), 'lots' => []];
        }

        $lines = JournalLine::query()
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.party_type', $customer->getMorphClass())
            ->where('journal_lines.party_id', $customer->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where(fn ($q) => $q->whereNull('journals.source_type')->orWhereNotIn('journals.source_type', self::ownMorphs()))
            ->when($asAt, fn ($q) => $q->whereDate('journals.journal_date', '<=', $asAt->toDateString()))
            ->orderBy('journals.journal_date')
            ->orderBy('journal_lines.id')
            ->get(['journal_lines.amount_minor', 'journals.journal_date', 'journals.journal_number']);

        $lots = [];
        $used = self::drawnFromOnAccount($customer, $asAt)->minor;

        foreach ($lines as $line) {
            $amount = (int) $line->amount_minor;

            // A credit on 2150 is money of theirs we took; a debit gave it back.
            if ($amount < 0) {
                $lots[] = [
                    'date' => Carbon::parse($line->journal_date)->toDateString(),
                    'reference' => (string) $line->journal_number,
                    'open' => -$amount,
                ];
            } else {
                $used += $amount;
            }
        }

        $balance = array_sum(array_column($lots, 'open')) - $used;

        foreach ($lots as $index => $lot) {
            $take = min($lot['open'], $used);
            $lots[$index]['open'] -= $take;
            $used -= $take;
        }

        $open = array_values(array_filter($lots, fn (array $lot) => $lot['open'] > 0));

        return [
            'balance' => Money::ofMinor($balance),
            'lots' => array_map(fn (array $lot) => [...$lot, 'open' => Money::ofMinor($lot['open'])], $open),
        ];
    }

    /**
     * The open advances as the screens offer them, OLDEST FIRST (Joy, R90: the
     * default the person may change): every advance document with something
     * open, then the on-account balance as one row (`advance` null) dated by
     * its oldest voucher.
     *
     * @return Collection<int, array{advance: CustomerAdvance|null, key: string, open: Money, date: string, number: string}>
     */
    public static function available(Customer $customer): Collection
    {
        $rows = self::openAdvances($customer)->map(fn (array $row) => [
            'advance' => $row['advance'],
            'key' => self::keyOf($row['advance']),
            'open' => $row['open'],
            'date' => $row['advance']->received_on->toDateString(),
            'number' => $row['advance']->number,
        ]);

        $pool = self::onAccountLots($customer);

        if ($pool['balance']->minor > 0 && $pool['lots'] !== []) {
            $rows->push([
                'advance' => null,
                'key' => self::keyOf(null),
                'open' => $pool['balance'],
                'date' => $pool['lots'][0]['date'],
                'number' => 'On account',
            ]);
        }

        return $rows
            ->sortBy(fn (array $row) => [$row['date'], $row['advance']?->id ?? PHP_INT_MAX])
            ->values();
    }

    /**
     * The default split for `$amount` (an invoice's outstanding): oldest first
     * until it is covered.
     *
     * @return list<array{key: string, advance_id: int|null, amount: Money}>
     */
    public static function proposal(Customer $customer, Money $amount): array
    {
        $left = $amount->minor;
        $proposal = [];

        foreach (self::available($customer) as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $row['open']->minor);
            $left -= $take;
            $proposal[] = ['key' => $row['key'], 'advance_id' => $row['advance']?->id, 'amount' => Money::ofMinor($take)];
        }

        return $proposal;
    }

    /** Their advance with us in total: open advance documents plus the on-account balance. */
    public static function totalOpen(Customer $customer): Money
    {
        return Money::sum(self::openAdvances($customer)->map(fn (array $row) => $row['open']))
            ->plus(Money::ofMinor(max(0, self::onAccount($customer)->minor)));
    }

    /** "advance:12", or "on_account" for the voucher balance: how a form names a row. */
    public static function keyOf(?CustomerAdvance $advance): string
    {
        return $advance === null ? 'on_account' : "advance:{$advance->id}";
    }

    /**
     * The screens' rows as plain arrays (the invoice page, the receive payment
     * screen, the advances page).
     *
     * @return list<array{key: string, advance_id: int|null, number: string, date: string, open: string}>
     */
    public static function rows(Customer $customer): array
    {
        return self::available($customer)->map(fn (array $row) => [
            'key' => $row['key'],
            'advance_id' => $row['advance']?->id,
            'number' => $row['number'],
            'date' => $row['date'],
            'open' => $row['open']->toTaka(),
        ])->values()->all();
    }

    /** The vouchers or journals an on-account draw of `$amount` reaches, oldest first. */
    public static function onAccountSourceNote(Customer $customer, Money $amount): string
    {
        $left = $amount->minor;
        $references = [];

        foreach (self::onAccountLots($customer)['lots'] as $lot) {
            if ($left <= 0) {
                break;
            }

            $references[] = $lot['reference'];
            $left -= $lot['open']->minor;
        }

        return mb_substr('On account from '.implode(', ', $references), 0, 255);
    }

    /**
     * ⚠⚠ A voucher or journal whose 2150 credit has since been used on an
     * invoice (or paid back) from the on-account balance cannot be reversed
     * underneath it: the invoice would stay paid by money the ledger no longer
     * holds. Asked by ReverseJournal for every reversal; the advance documents'
     * own journals are guarded by their own reversals and skipped here.
     *
     * @throws ValidationException
     */
    public static function assertReversalKeepsOnAccount(Journal $journal): void
    {
        $account = self::account();

        if ($account === null || ($journal->source_type !== null && in_array($journal->source_type, self::ownMorphs(), true))) {
            return;
        }

        $customerMorph = (new Customer)->getMorphClass();
        $removed = [];

        foreach ($journal->lines as $line) {
            if ((int) $line->account_id === $account->id && $line->party_type === $customerMorph) {
                // A credit taken away lowers what we hold of theirs.
                $removed[(int) $line->party_id] = ($removed[(int) $line->party_id] ?? 0) - (int) $line->amount_minor;
            }
        }

        foreach ($removed as $customerId => $minor) {
            $customer = Customer::query()->find($customerId);

            if ($customer === null || $minor <= 0) {
                continue;
            }

            $balance = self::onAccount($customer);

            if ($balance->minor - $minor < 0) {
                throw ValidationException::withMessages([
                    'reason' => "This entry took an advance from {$customer->name} that has since been used on an invoice or paid back. Reverse that use or refund first (".Money::ofMinor($minor - max(0, $balance->minor))->format().' of it is in use).',
                ]);
            }
        }
    }

    /** @return list<string> */
    public static function ownMorphs(): array
    {
        return array_map(fn (string $class) => (new $class)->getMorphClass(), self::OWN_SOURCES);
    }

    /** What applications and refunds have drawn from the on-account balance (live ones, or standing on `$asAt`). */
    private static function drawnFromOnAccount(Customer $customer, ?Carbon $asAt): Money
    {
        $scope = function (Builder $query, string $dateColumn) use ($customer, $asAt): Builder {
            $query->where('customer_id', $customer->id)->whereNull('customer_advance_id');

            return $asAt === null
                ? $query->live()
                : $query->whereDate($dateColumn, '<=', $asAt->toDateString())->standingOn($asAt->toDateString());
        };

        return Money::sum([
            Money::ofTaka((string) ($scope(CustomerAdvanceApplication::query(), 'applied_on')->sum('amount') ?: '0')),
            Money::ofTaka((string) ($scope(CustomerAdvanceRefund::query(), 'refunded_on')->sum('amount') ?: '0')),
        ]);
    }
}
