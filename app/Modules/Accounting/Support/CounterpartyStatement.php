<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One counterparty's ledgers, straight from the party-tagged journal lines
 * (loans plan v2, phase 1): the twin of EmployeeStatement for a lender or a
 * borrower.
 *
 * Each loan head is its own running ledger with an opening figure, because
 * principal lent, interest owed to us and a bank loan owed by us do not add
 * into one honest balance. A head appears only when it moved in the period or
 * carries an opening balance; the other ten would be rows of zeros.
 *
 * ⚠ Built from the LEDGER, so what it shows is what the books say, in THIS
 * business (journal lines carry their business). PartyLedgers guarantees no
 * loan line is missing its counterparty, so the sum of every counterparty's
 * statement is the head (proved by the reconciliation screen).
 */
final class CounterpartyStatement
{
    /**
     * @param  Collection<int, array{account: Account, opening: Money, debits: Money, credits: Money, closing: Money, lines: Collection<int, array{date: string, journal_number: string, journal_id: int, memo: ?string, source: ?string, amount: Money, balance: Money}>}>  $heads
     */
    private function __construct(
        public readonly Counterparty $counterparty,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $heads,
    ) {}

    public static function for(Counterparty $counterparty, Carbon $from, Carbon $to): self
    {
        $heads = CounterpartyBalances::heads()
            ->map(function (Account $account) use ($counterparty, $from, $to) {
                $mine = fn () => JournalLine::query()
                    ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                    ->where('journal_lines.account_id', $account->id)
                    ->where('journal_lines.party_type', $counterparty->getMorphClass())
                    ->where('journal_lines.party_id', $counterparty->getKey());

                $opening = Money::ofMinor((int) $mine()->where('journals.journal_date', '<', $from->toDateString())->sum('journal_lines.amount_minor'));

                $lines = $mine()
                    ->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])
                    ->orderBy('journals.journal_date')->orderBy('journals.id')->orderBy('journal_lines.line_no')
                    ->with('journal')
                    ->select('journal_lines.*')
                    ->get();

                if ($opening->isZero() && $lines->isEmpty()) {
                    return null;
                }

                $balance = $opening;
                $debits = Money::zero();
                $credits = Money::zero();

                $rows = $lines->map(function (JournalLine $line) use (&$balance, &$debits, &$credits) {
                    $amount = $line->amount();
                    $balance = $balance->plus($amount);

                    if ($amount->isDebit()) {
                        $debits = $debits->plus($amount);
                    } else {
                        $credits = $credits->plus($amount->absolute());
                    }

                    return [
                        'date' => $line->journal->journal_date->toDateString(),
                        'journal_number' => $line->journal->journal_number,
                        'journal_id' => $line->journal_id,
                        'memo' => $line->memo ?? $line->journal->memo,
                        'source' => JournalSourceCatalog::label($line->journal->source_type),
                        'amount' => $amount,
                        'balance' => $balance,
                    ];
                });

                return [
                    'account' => $account,
                    'opening' => $opening,
                    'debits' => $debits,
                    'credits' => $credits,
                    'closing' => $balance,
                    'lines' => $rows->values(),
                ];
            })
            ->filter()
            ->values();

        return new self($counterparty, $from, $to, $heads);
    }
}
