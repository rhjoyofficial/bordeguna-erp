<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Models\VoucherLine;
use Illuminate\Support\Collection;

/**
 * What a ledger row should SAY when its journal came from a voucher (Joy,
 * 12 Oct 2026). The journal line's own memo is written by the poster as
 * "account (number) - party", which only repeats the Account and Party
 * columns beside it. The person who made the voucher wrote what it was for:
 * a narration on each line, and one for the whole voucher ("Being ...").
 *
 * For a row the description is, in order:
 *  1. the narration of the voucher line(s) on the OTHER side of that row
 *     (a bank row on a payment shows what the money was paid for);
 *  2. the voucher's own narration;
 *  3. null, so the caller keeps the journal memo (payroll, opening balances,
 *     corrections and every other journal that no voucher made).
 *
 * ⚠ A reversal journal posts every line on the opposite side, so its sides
 * are flipped back before the voucher's lines are compared with them.
 *
 * ⚠ One query for the vouchers and one for their lines, for a whole page of
 * journals. Asking per row is the N+1 this class exists to avoid.
 */
final class VoucherNarrations
{
    /**
     * @param  array<int, array{voucher: Voucher, reversal: bool}>  $byJournal
     */
    private function __construct(private readonly array $byJournal) {}

    /**
     * @param  iterable<int|string|null>  $journalIds
     */
    public static function forJournals(iterable $journalIds): self
    {
        $ids = collect($journalIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return new self([]);
        }

        $vouchers = Voucher::query()
            ->where(fn ($query) => $query->whereIn('journal_id', $ids)->orWhereIn('reversal_journal_id', $ids))
            ->with(['lines' => fn ($query) => $query->orderBy('line_no')])
            ->get(['id', 'voucher_number', 'status', 'counterparty', 'narration', 'journal_id', 'reversal_journal_id']);

        $byJournal = [];
        foreach ($vouchers as $voucher) {
            if ($voucher->journal_id !== null && $ids->contains((int) $voucher->journal_id)) {
                $byJournal[(int) $voucher->journal_id] = ['voucher' => $voucher, 'reversal' => false];
            }
            if ($voucher->reversal_journal_id !== null && $ids->contains((int) $voucher->reversal_journal_id)) {
                $byJournal[(int) $voucher->reversal_journal_id] = ['voucher' => $voucher, 'reversal' => true];
            }
        }

        return new self($byJournal);
    }

    /** The voucher behind a journal, if a voucher made it (or reversed it). */
    public function voucherFor(int $journalId): ?Voucher
    {
        return $this->byJournal[$journalId]['voucher'] ?? null;
    }

    /** The voucher's narration for a whole journal, or null. */
    public function forJournal(int $journalId): ?string
    {
        $voucher = $this->voucherFor($journalId);

        return $voucher === null ? null : self::clean($voucher->narration);
    }

    /**
     * What a general-ledger row should say: the counter line's narration,
     * else the voucher narration, else null.
     */
    public function forLedgerRow(JournalLine $line): ?string
    {
        $found = $this->byJournal[(int) $line->journal_id] ?? null;

        if ($found === null) {
            return null;
        }

        $rowIsDebit = $this->voucherSideIsDebit($line, $found['reversal']);

        $counter = $found['voucher']->lines
            ->filter(fn (VoucherLine $voucherLine) => $voucherLine->isDebit() !== $rowIsDebit)
            ->map(fn (VoucherLine $voucherLine) => self::clean($voucherLine->narration))
            ->filter()
            ->unique()
            ->values();

        return self::joined($counter) ?? self::clean($found['voucher']->narration);
    }

    /**
     * What a day-book line should say: that same line's own narration on the
     * voucher (same account, same side), else null.
     */
    public function forOwnLine(JournalLine $line): ?string
    {
        $found = $this->byJournal[(int) $line->journal_id] ?? null;

        if ($found === null) {
            return null;
        }

        $isDebit = $this->voucherSideIsDebit($line, $found['reversal']);

        $own = $found['voucher']->lines
            ->filter(fn (VoucherLine $voucherLine) => (int) $voucherLine->account_id === (int) $line->account_id && $voucherLine->isDebit() === $isDebit)
            ->map(fn (VoucherLine $voucherLine) => self::clean($voucherLine->narration))
            ->filter()
            ->unique()
            ->values();

        return self::joined($own);
    }

    private function voucherSideIsDebit(JournalLine $line, bool $reversal): bool
    {
        return $reversal ? ! $line->isDebit() : $line->isDebit();
    }

    /** @param  Collection<int, string>  $texts */
    private static function joined(Collection $texts): ?string
    {
        return $texts->isEmpty() ? null : $texts->implode('; ');
    }

    private static function clean(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
