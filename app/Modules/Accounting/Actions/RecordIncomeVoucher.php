<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money that came in and was not a sale.
 *
 *   Dr  the money account it landed in
 *       Cr  each income head named on the lines
 *
 * The exact mirror of RecordExpenseVoucher, and deliberately so: the same
 * period lock, the same future-date refusal, the same numbering scheme, the
 * same insistence that the document cannot exist without the chart. An income
 * voucher's entire content IS the classification - without accounts there is
 * nothing to record but an amount.
 */
class RecordIncomeVoucher
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @param  array<int, array{account: Account, amount: string, description?: string}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $moneyAccount,
        mixed $receivedOn,
        array $lines,
        User $createdBy,
        ?Customer $customer = null,
        ?string $receivedFrom = null,
        ?string $reference = null,
        ?string $note = null,
        // ⭐ Edit, delete and correct, Phase 0 (17 Oct 2026). Both are set ONLY
        // by IncomeVoucherCorrector: the Owner's one-document exception for a
        // closed month, and the number of the voucher this one corrects (it
        // goes into the journal memo; the label is not part of the number).
        ?ClosedPeriodPass $pass = null,
        ?string $correctionOf = null,
    ): IncomeVoucher {
        $date = Carbon::parse($receivedOn)->startOfDay();

        AccountingPeriod::assertOpenFor($date, $pass);

        if ($date->isFuture()) {
            throw ValidationException::withMessages([
                'received_on' => 'A voucher cannot be dated in the future.',
            ]);
        }

        $businessId = app(BusinessContext::class)->id();

        if ($moneyAccount->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        if (($refusal = $moneyAccount->handRefusal()) !== null) {
            throw ValidationException::withMessages(['money_account_id' => $refusal]);
        }

        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $date);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => Money::ofTaka($line['amount'])->minor > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A voucher needs at least one line with an amount.',
            ]);
        }

        foreach ($lines as $index => $line) {
            $account = $line['account'];

            // ⚠ An income voucher may only ever credit an INCOME account.
            // Without this the document becomes a free hand over the whole
            // ledger - somebody could book a bank deposit against Accounts
            // Payable and the books would still balance perfectly.
            if ($account->type !== AccountType::Income) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => "{$account->label()} is not an income account.",
                ]);
            }

            if (! $account->is_postable || ! $account->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => "{$account->label()} cannot be posted to.",
                ]);
            }

            // ⚠⚠ THE ONE RULE THIS DOCUMENT HAS THAT THE EXPENSE SIDE DOES NOT,
            // and it is the whole boundary of the feature. A sale recorded here
            // would carry NO customer on the receivable, NO stock movement and
            // NO cost of goods, so the gross margin would be a fiction and the
            // sales module and the ledger would disagree about what was sold.
            // Sales revenue has exactly one road in: an invoice or a counter
            // sale. The contra pair is refused for the same reason - a return
            // or a discount belongs to the sale it reverses, not to a voucher
            // somebody typed.
            if (in_array($account->system_slug, self::SALES_ONLY, true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => "{$account->label()} is a sales account. Raise an invoice or a counter sale instead - this voucher is for income that is not a sale.",
                ]);
            }
        }

        return DB::transaction(function () use ($moneyAccount, $date, $lines, $createdBy, $customer, $receivedFrom, $reference, $note, $businessId, $pass, $correctionOf) {
            $total = Money::sum(array_map(fn (array $line) => Money::ofTaka($line['amount']), $lines));

            // ⚠ claim(), not next() (17 Oct 2026, ChatGPT REPLY-6 4.5): the
            // number is taken inside a savepoint and a collision retries with
            // the next free one. A correction that rolls back later leaves no
            // row, so no number is used up.
            $voucher = DocumentNumber::claim(
                'voucher_number',
                fn () => $this->nextNumber($businessId, $date),
                fn (string $number) => IncomeVoucher::create([
                    'voucher_number' => $number,
                    'received_on' => $date,
                    'money_account_id' => $moneyAccount->id,
                    'customer_id' => $customer?->id,
                    'received_from' => $receivedFrom,
                    'total' => $total->toTaka(),
                    'reference' => $reference,
                    'note' => $note,
                    'created_by' => $createdBy->id,
                ]),
            );

            $journalLines = [];

            $journalLines[] = JournalLineDraft::debit(
                $moneyAccount->account_id,
                $total,
                $moneyAccount->label(),
                $customer,
                $moneyAccount,
            );

            foreach ($lines as $line) {
                $amount = Money::ofTaka($line['amount']);

                $voucher->lines()->create([
                    'business_id' => $voucher->business_id,
                    'account_id' => $line['account']->id,
                    'description' => $line['description'] ?? null,
                    'amount' => $amount->toTaka(),
                ]);

                $journalLines[] = JournalLineDraft::credit($line['account']->id, $amount, $line['description'] ?? null);
            }

            $journalLines = LedgerRounding::balance($journalLines, $date, "Rounding on voucher {$voucher->voucher_number}");

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: $journalLines,
                memo: $correctionOf === null
                    ? "Income voucher {$voucher->voucher_number} - {$voucher->payer()}"
                    : "Income voucher {$voucher->voucher_number} - Correction of {$correctionOf} - {$voucher->payer()}",
                source: $voucher,
            ), $createdBy, pass: $pass);

            // The voucher names its one journal, so a reversal never has to
            // guess which journal is "the" journal (data check D1).
            $voucher->forceFill(['journal_id' => $journal->id])->saveQuietly();

            return $voucher->fresh('lines');
        });
    }

    /**
     * The income accounts this document refuses, by slug rather than by code,
     * so renumbering the chart can never quietly reopen the door.
     *
     * ⚠ Public because the SCREEN must hide exactly what the ACTION refuses.
     * Two copies of this list would drift, and the day they did the picker
     * would offer a choice that always fails.
     *
     * @var list<string>
     */
    public const SALES_ONLY = ['sales_revenue', 'sales_returns', 'discounts_allowed'];

    /**
     * `INC-2026-27-0001`, restarting each fiscal year.
     *
     * ⚠ Max-plus-one inside the transaction rather than a locked sequence row,
     * the same trade the expense voucher makes and for the same reason:
     * vouchers are entered by one person at a keyboard. The unique index on
     * (business_id, voucher_number) is the real guarantee, so a genuine race
     * surfaces as a rejected save somebody retries, never as two vouchers
     * sharing a number.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $label = FiscalYear::containing($date, $business)->label();
        $prefix = "INC-{$label}-";

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(IncomeVoucher::query(), 'voucher_number', $prefix);
    }
}
