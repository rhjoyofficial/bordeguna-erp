<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One employee's ledgers, straight from the party-tagged journal lines.
 *
 * The accountant's "ledger head per employee" (26 Sep 2026): for Musa Islam,
 * what he was advanced and has repaid (1170), what salary he is still owed
 * (2130), what was deducted from him and is held (2140), and what the business
 * owes him for expenses he paid (2145). Each head is its own running ledger
 * with an opening figure, because an asset and three liabilities do not add
 * into one honest balance.
 *
 * ⚠ Built from the LEDGER, not from payslips or loan schedules, so what it
 * shows is what the books say. Per head, every figure is the sum of lines that
 * name this employee; PartyLedgers guarantees no line on 1170/2130/2140 is
 * missing its employee, so the sum of every employee's statement is the
 * control head.
 */
final class EmployeeStatement
{
    /**
     * @param  Collection<int, array{account: Account, opening: Money, debits: Money, credits: Money, closing: Money, lines: Collection<int, array{date: string, journal_number: string, memo: ?string, source: ?string, amount: Money, balance: Money}>}>  $heads
     */
    private function __construct(
        public readonly Employee $employee,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $heads,
    ) {}

    public static function for(Employee $employee, Carbon $from, Carbon $to): self
    {
        $accounts = Account::query()->whereIn('system_slug', PartyLedgers::EMPLOYEE_SLUGS)->get()->keyBy('system_slug');

        $heads = collect(PartyLedgers::EMPLOYEE_SLUGS)
            ->map(fn (string $slug) => $accounts->get($slug))
            ->filter()
            ->map(function (Account $account) use ($employee, $from, $to) {
                $mine = fn () => JournalLine::query()
                    ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                    ->where('journal_lines.account_id', $account->id)
                    ->where('journal_lines.party_type', $employee->getMorphClass())
                    ->where('journal_lines.party_id', $employee->getKey());

                $opening = Money::ofMinor((int) $mine()->where('journals.journal_date', '<', $from->toDateString())->sum('journal_lines.amount_minor'));

                $lines = $mine()
                    ->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])
                    ->orderBy('journals.journal_date')->orderBy('journals.id')->orderBy('journal_lines.line_no')
                    ->with('journal')
                    ->select('journal_lines.*')
                    ->get();

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

                    $source = $line->journal?->source_type;

                    return [
                        'date' => $line->journal->journal_date->toDateString(),
                        'journal_number' => $line->journal->journal_number,
                        'memo' => $line->memo ?? $line->journal->memo,
                        'source' => JournalSourceCatalog::label($source),
                        'journal_id' => $line->journal_id,
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
                    'lines' => $rows,
                ];
            })
            ->values();

        return new self($employee, $from, $to, $heads);
    }
}
