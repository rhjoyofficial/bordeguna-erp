<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\JournalSequence;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;

/**
 * ⚠⚠ THE ONLY WAY INTO THE GENERAL LEDGER.
 *
 * Nothing else in this codebase may create a Journal or a JournalLine, and
 * tests/Feature/Accounting/JournalStructureTest.php scans the source and fails,
 * naming the offender, if anything tries. That test is verified by
 * reintroducing the defect, because a structural test that has never failed is a
 * comment with a longer runtime.
 *
 * This is Joy's standing Inventory rule one module over: "everything that
 * changes value must go through the same ledger and the same pipeline; never
 * multiple code paths that can modify it independently". The first question
 * about any future accounting change is whether it adds a second way to write a
 * journal line. If it does, it is wrong however much shorter it is.
 *
 * ⚠ It REFUSES rather than returning false. A caller that forgets to check a
 * boolean writes a broken entry into the books, which is the same reasoning that
 * made AccountingPeriod::assertOpenFor throw.
 */
class PostJournal
{
    /**
     * ⚠ `$reverses` is a parameter here rather than a field on JournalDraft, and
     * the distinction is deliberate: the draft is the source-agnostic interface
     * every subsystem shares, while "which journal does this undo" is a fact
     * about the LEDGER. Putting it here also means the link is written inside
     * the same transaction as the journal, so nothing ever has to reach back and
     * mutate a posted row - which the model would refuse anyway, and rightly.
     */
    public function handle(JournalDraft $draft, User $postedBy, ?Journal $reverses = null): Journal
    {
        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            throw JournalException::noBusiness();
        }

        $this->assertDateIsUsable($draft);
        $this->assertAccountsArePostable($draft);
        $this->assertBalanced($draft);
        $this->assertMoneyAccountsBelongHere($draft, $businessId);
        $this->assertPartyLedgersNameTheirParty($draft);

        // ⚠ A reversal posts back exactly what the original posted, money
        // account and all, so it is never re-stamped or refused here.
        $lines = $reverses === null ? $this->withMoneyHeadsOwned($draft, $businessId) : $draft->lines;

        return DB::transaction(function () use ($draft, $lines, $postedBy, $businessId, $reverses) {
            $business = Business::withoutGlobalScopes()->findOrFail($businessId);

            $journal = new Journal;
            $journal->business_id = $businessId;
            $journal->journal_number = $this->nextNumber($business, $draft);
            // ⚠ Stored, never consulted. The engine does not ask what kind of
            // journal this is, and a structural test keeps it that way.
            $journal->journal_type = $draft->type;
            $journal->journal_date = $draft->date->toDateString();
            $journal->memo = $draft->memo;
            $journal->posted_by = $postedBy->id;
            $journal->posted_at = now();
            $journal->reverses_journal_id = $reverses?->id;

            if ($draft->source !== null) {
                $journal->source_type = $draft->source->getMorphClass();
                $journal->source_id = $draft->source->getKey();
            }

            $journal->save();

            foreach ($lines as $index => $lineDraft) {
                $line = new JournalLine;
                $line->journal_id = $journal->id;
                $line->business_id = $businessId;
                $line->line_no = $index + 1;
                $line->account_id = $lineDraft->accountId;
                $line->amount_minor = $lineDraft->amount->minor;
                $line->memo = $lineDraft->memo;
                $line->money_account_id = $lineDraft->moneyAccount?->id;

                if ($lineDraft->party !== null) {
                    $line->party_type = $lineDraft->party->getMorphClass();
                    $line->party_id = $lineDraft->party->getKey();
                }

                $line->save();
            }

            return $journal->refresh();
        });
    }

    /**
     * ⚠ The period lock is re-checked here rather than trusted from a screen,
     * and it is the SAME lock Inventory and payroll disbursement use. One system
     * has one closed month; two locks that can disagree is worse than either.
     */
    private function assertDateIsUsable(JournalDraft $draft): void
    {
        if ($draft->date->isFuture()) {
            throw JournalException::futureDate($draft->date->toDateString());
        }

        AccountingPeriod::assertOpenFor($draft->date);
    }

    private function assertAccountsArePostable(JournalDraft $draft): void
    {
        if (count($draft->lines) < 2) {
            throw JournalException::tooFewLines();
        }

        $accounts = Account::query()
            ->whereIn('id', array_map(fn (JournalLineDraft $line) => $line->accountId, $draft->lines))
            ->get()
            ->keyBy('id');

        foreach ($draft->lines as $index => $line) {
            if ($line->amount->isZero()) {
                throw JournalException::zeroLine($index + 1);
            }

            $account = $accounts->get($line->accountId);

            if ($account === null) {
                throw JournalException::notPostable(new Account(['code' => (string) $line->accountId, 'name' => 'Unknown account']));
            }

            // ⚠ Posting to a heading would double-count it against its own
            // children, which is the quiet way a set of books stops adding up.
            if (! $account->is_postable) {
                throw JournalException::notPostable($account);
            }

            if (! $account->is_active) {
                throw JournalException::inactiveAccount($account);
            }
        }
    }

    /**
     * ⚠⚠ THE INVARIANT THE WHOLE MODULE RESTS ON, checked at WRITE time.
     *
     * There is no unbalanced draft state in the ledger. Drafts live in the
     * document that produces them, and by the time something reaches here it
     * either balances exactly or it is refused. "Exactly" is possible only
     * because the amounts are whole paisa - see Support\Money.
     */
    private function assertPartyLedgersNameTheirParty(JournalDraft $draft): void
    {
        // ⚠⚠ A line on a party-kept control head (Support\PartyLedgers: advances
        // to suppliers and from customers, employee advances, deductions payable,
        // salaries payable) ALWAYS names its party, whichever module posts it.
        // That one rule is what makes "1170 > Musa Islam" a ledger: the parties
        // add up to the control head, or the reconciliation screen says so (R7).
        // Checked here, on the only way into the ledger, not in the screens
        // (26 Sep 2026).
        $accounts = Account::query()->findMany(collect($draft->lines)->pluck('accountId')->unique())->keyBy('id');

        foreach ($draft->lines as $index => $line) {
            $account = $accounts->get($line->accountId);

            if ($account === null || ($class = PartyLedgers::partyClassFor($account)) === null) {
                continue;
            }

            if (! $line->party instanceof $class) {
                throw JournalException::partyRequired($account, PartyLedgers::noun($class), $index + 1);
            }
        }
    }

    private function assertBalanced(JournalDraft $draft): void
    {
        $total = $draft->total();

        if (! $total->isZero()) {
            throw JournalException::unbalanced($total);
        }
    }

    /**
     * ⚠⚠ A CASH, BANK OR WALLET HEAD IS POSTED ONLY THROUGH ITS OWN MONEY ACCOUNT
     * (21 Sep 2026, Support\MoneyLedgers). Checked here, at the one place every
     * posting passes, rather than on each screen.
     *
     * - A line naming a money account must sit on that account's own head: BRAC's
     *   money on bKash's ledger is a cash book that no longer adds up.
     * - A line naming none (a manual journal, an opening balance) is stamped with
     *   the head's one money account in THIS business, so the bank statement
     *   match, the cash forecast and the cockpit all see it.
     * - A head whose money account belongs to another business, or that no money
     *   account reports under, is refused: the chart is shared by four businesses,
     *   and LaLaDia posting to Bor de Guna's BRAC Bank is the leak this closes.
     *
     * @return list<JournalLineDraft>
     */
    private function withMoneyHeadsOwned(JournalDraft $draft, int $businessId): array
    {
        $groupIds = MoneyLedgers::groupIds();

        if ($groupIds === []) {
            return $draft->lines;
        }

        $moneyHeads = Account::query()
            ->whereIn('id', array_map(fn (JournalLineDraft $line) => $line->accountId, $draft->lines))
            ->whereIn('parent_id', $groupIds)
            ->get()
            ->keyBy('id');

        return array_map(function (JournalLineDraft $line) use ($moneyHeads, $businessId) {
            $head = $moneyHeads->get($line->accountId);

            if ($head === null) {
                return $line;
            }

            if ($line->moneyAccount !== null) {
                if ((int) $line->moneyAccount->account_id !== $head->id) {
                    throw JournalException::moneyHeadOfAnotherAccount($head, $line->moneyAccount->name);
                }

                return $line;
            }

            $owners = MoneyLedgers::ownersOf($head->id);
            $here = $owners->where('business_id', $businessId);

            if ($here->count() > 1) {
                throw JournalException::moneyHeadShared($head);
            }

            if ($owners->isEmpty()) {
                throw JournalException::moneyHeadWithoutAccount($head);
            }

            if ($here->isEmpty()) {
                throw JournalException::moneyHeadOfAnotherBusiness($head);
            }

            return JournalLineDraft::signed($line->accountId, $line->amount, $line->memo, $line->party, $here->first());
        }, $draft->lines);
    }

    private function assertMoneyAccountsBelongHere(JournalDraft $draft, int $businessId): void
    {
        foreach ($draft->lines as $line) {
            if ($line->moneyAccount !== null && $line->moneyAccount->business_id !== $businessId) {
                throw JournalException::foreignAccount();
            }
        }
    }

    /**
     * ⚠ The sequence row is locked and RE-READ inside the transaction. The
     * re-read is what makes it correct; `lockForUpdate` is a no-op on SQLite, so
     * a lock-only fix passes the whole suite and races in production. Third
     * application of the I3 lesson.
     *
     * ⚠ The unique index on (business_id, journal_number) is the real
     * guarantee. This method makes collisions not happen; the database makes
     * them impossible.
     */
    private function nextNumber(Business $business, JournalDraft $draft): string
    {
        $fiscalYear = FiscalYear::containing($draft->date, $business);

        $sequence = JournalSequence::query()
            ->where('business_id', $business->id)
            ->where('fiscal_year', $fiscalYear->startingYear)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequence = JournalSequence::create([
                'business_id' => $business->id,
                'fiscal_year' => $fiscalYear->startingYear,
                'next_number' => 1,
            ]);

            $sequence = JournalSequence::query()->whereKey($sequence->id)->lockForUpdate()->first();
        }

        $number = (int) $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        // C-LGR-2, taken as a default Joy can change: JV-2026-27-0001. The
        // format lives here and nowhere else, so changing it is one edit.
        return sprintf('JV-%s-%04d', $fiscalYear->label(), $number);
    }
}
