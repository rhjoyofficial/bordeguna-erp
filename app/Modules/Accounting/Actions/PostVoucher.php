<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a draft voucher into a posted one: a number, one journal, no way back.
 *
 * ⚠⚠ THE ONLY PLACE A VOUCHER MEETS THE LEDGER, and it meets it through
 * PostJournal like every other document. No voucher type has posting rules of
 * its own; the four differ only in how the lines below are laid out:
 *
 *   Payment   Dr each line              Cr the money account (total)
 *   Receipt   Dr the money account      Cr each line
 *   Contra    Dr the receiving account  Cr the sending account
 *   Journal   each line on its own side; must balance
 *
 * ⚠ Everything a draft was allowed to leave unfinished is insisted on here.
 */
class PostVoucher
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(Voucher $voucher, User $by): Voucher
    {
        return DB::transaction(function () use ($voucher, $by) {
            // ⚠ Locked and re-read: two people pressing Post on one draft must
            // produce one journal, not two.
            $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if (! $voucher->isDraft()) {
                throw ValidationException::withMessages(['voucher' => "{$voucher->title()} is already posted."]);
            }

            $voucher->load(['lines.account', 'lines.party', 'moneyAccount', 'toMoneyAccount']);

            $date = $voucher->voucher_date->copy()->startOfDay();
            AccountingPeriod::assertOpenFor($date);

            if ($date->isFuture()) {
                throw ValidationException::withMessages(['voucher_date' => 'A voucher cannot be posted with a future date.']);
            }

            $lines = $this->journalLines($voucher);

            // ⚠ The number is taken only now, after every refusal above, so a
            // voucher that fails to post burns no number.
            $type = $voucher->type;
            $number = $type === VoucherType::Journal ? null : $this->nextNumber($voucher);

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: $lines,
                memo: $this->memo($voucher, $number),
                source: $voucher,
            ), $by);

            $voucher->update([
                // A journal voucher IS its journal: it carries the journal's own
                // JV number rather than a second JV stream beside it.
                'voucher_number' => $number ?? $journal->journal_number,
                'status' => VoucherStatus::Posted,
                'journal_id' => $journal->id,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ]);

            return $voucher->fresh(['lines', 'journal']);
        });
    }

    /**
     * @return array<int, JournalLineDraft>
     */
    private function journalLines(Voucher $voucher): array
    {
        $type = $voucher->type;
        $date = $voucher->voucher_date->copy()->startOfDay();

        if ($type === VoucherType::Contra) {
            $from = $this->usable($voucher->moneyAccount, 'money_account_id', $date);
            $to = $this->usable($voucher->toMoneyAccount, 'to_money_account_id', $date);

            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_money_account_id' => 'Money cannot be moved into the account it came from.']);
            }

            $amount = $voucher->total();

            if ($amount->minor <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount that was moved.']);
            }

            return [
                JournalLineDraft::debit($to->account_id, $amount, $voucher->narration ?? "From {$from->label()}", null, $to),
                JournalLineDraft::credit($from->account_id, $amount, $voucher->narration ?? "To {$to->label()}", null, $from),
            ];
        }

        $rows = $voucher->lines->filter(fn ($line) => $line->amount_minor > 0)->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'A voucher needs at least one line with an amount.']);
        }

        $lines = [];

        foreach ($rows as $index => $row) {
            if (($refusal = VoucherAccounts::refusal($row->account, $date)) !== null) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => $refusal]);
            }

            $partyClass = VoucherAccounts::partyClassFor($row->account);

            if ($partyClass !== null && $row->party === null) {
                $who = class_basename($partyClass) === 'Supplier' ? 'supplier' : 'customer';

                throw ValidationException::withMessages(["lines.{$index}.party_id" => "Say which {$who} this advance belongs to."]);
            }

            $lines[] = $row->isDebit()
                ? JournalLineDraft::debit($row->account_id, $row->amount(), $row->narration ?? $voucher->narration, $row->party)
                : JournalLineDraft::credit($row->account_id, $row->amount(), $row->narration ?? $voucher->narration, $row->party);
        }

        if ($type === VoucherType::Journal) {
            if ($rows->count() < 2) {
                throw ValidationException::withMessages(['lines' => 'A journal voucher needs at least two lines.']);
            }

            $debits = $rows->filter->isDebit()->sum('amount_minor');
            $credits = $rows->reject->isDebit()->sum('amount_minor');

            if ($debits !== $credits) {
                $gap = Money::ofMinor(abs($debits - $credits))->format();

                throw ValidationException::withMessages(['lines' => "Debits and credits differ by {$gap}. A journal voucher must balance."]);
            }

            return $lines;
        }

        $money = $this->usable($voucher->moneyAccount, 'money_account_id', $date);
        $total = Money::ofMinor((int) $rows->sum('amount_minor'));
        $label = trim($money->label().($voucher->counterparty ? " - {$voucher->counterparty}" : ''));

        $lines[] = $type === VoucherType::Payment
            ? JournalLineDraft::credit($money->account_id, $total, $label, null, $money)
            : JournalLineDraft::debit($money->account_id, $total, $label, null, $money);

        return $lines;
    }

    private function usable(?MoneyAccount $account, string $field, mixed $date): MoneyAccount
    {
        if ($account === null) {
            throw ValidationException::withMessages([$field => 'Choose the cash, bank or mobile account.']);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => "{$account->name} is no longer active."]);
        }

        if ($account->isCourier() || $account->isGateway()) {
            throw ValidationException::withMessages([$field => "{$account->name} is settled by its own statements, not by a voucher."]);
        }

        if ($account->account_id === null) {
            throw MappingNotFound::for("money account [{$account->name}]", $date);
        }

        return $account;
    }

    private function memo(Voucher $voucher, ?string $number): string
    {
        $head = trim($voucher->type->label().' '.($number ?? ''));
        $tail = $voucher->counterparty ?? $voucher->narration;

        return $tail === null ? $head : "{$head} - ".mb_strimwidth($tail, 0, 200, '…');
    }

    /**
     * PV-2026-27-0001, per business, per type, per fiscal year.
     *
     * ⚠ A counter row under lock, not "highest number + 1": the expense
     * voucher's max()+1 reads a number a concurrent transaction is about to
     * take, and the unique index then fails one of two honest users.
     */
    private function nextNumber(Voucher $voucher): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($voucher->business_id);
        $fiscalYear = FiscalYear::containing($voucher->voucher_date, $business);
        $key = ['business_id' => $business->id, 'type' => $voucher->type->value, 'fiscal_year' => $fiscalYear->startingYear];

        if (! DB::table('voucher_sequences')->where($key)->exists()) {
            DB::table('voucher_sequences')->insertOrIgnore($key + ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $row = DB::table('voucher_sequences')->where($key)->lockForUpdate()->first();
        DB::table('voucher_sequences')->where('id', $row->id)->update(['next_number' => $row->next_number + 1, 'updated_at' => now()]);

        return sprintf('%s-%s-%04d', $voucher->type->prefix(), $fiscalYear->label(), $row->next_number);
    }
}
