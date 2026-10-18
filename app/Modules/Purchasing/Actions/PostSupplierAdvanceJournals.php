<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The journals of the advance lifecycle (ruling P2, 18 Oct 2026).
 *
 *   advance       Dr 1160 Advances to Suppliers (party)   Cr money account (net)
 *                                                          Cr 2180 TDS Payable (held back)
 *   opening       Dr 1160 (party)                          Cr 3900 Opening Balance Equity
 *   application   Dr 2110 Accounts Payable (party)         Cr 1160 (party)
 *   refund        Dr money account                         Cr 1160 (party)
 *
 * ⚠ An OVERPAYMENT advance has no journal of its own: its 1160 line is on the
 * supplier payment's journal (PostPurchaseJournals::forPayment), because it is
 * one remittance and the bank statement shows it once.
 *
 * ⚠ Same gate as every purchasing journal: with no `inventory.stock_on_hand`
 * mapping the business is not posting purchases yet, and nothing is posted.
 */
class PostSupplierAdvanceJournals
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function forAdvance(SupplierAdvance $advance, User $postedBy): ?Journal
    {
        if ($advance->kind === 'overpayment' || $this->existingJournal($advance) !== null) {
            return null;
        }

        $on = Carbon::parse($advance->paid_on)->startOfDay();

        if (! $this->posting($on)) {
            return null;
        }

        $advance->loadMissing(['supplier', 'moneyAccount']);
        $total = $advance->money();

        if ($advance->kind === 'opening') {
            return $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: [
                    JournalLineDraft::debit($this->advances()->id, $total, "Opening advance {$advance->number}", $advance->supplier),
                    JournalLineDraft::credit(Account::bySlug('opening_balance_equity')->id, $total, "Opening advance {$advance->number}"),
                ],
                memo: "Opening advance {$advance->number} - {$advance->supplier->name}",
                source: $advance,
                type: JournalType::OpeningBalance,
            ), $postedBy);
        }

        $lines = [
            JournalLineDraft::debit($this->advances()->id, $total, $advance->reference ?? $advance->number, $advance->supplier),
            JournalLineDraft::credit($this->bankOf($advance->moneyAccount, $on)->id, $advance->netPaid(), $advance->moneyAccount->label(), null, $advance->moneyAccount),
        ];

        // Ruling P2: tax held back at the advance is recorded separately, never
        // hidden in a smaller advance.
        foreach (['tds' => 'tds_payable', 'vds' => 'vds_payable'] as $kind => $slug) {
            $held = Money::ofTaka((string) $advance->{"{$kind}_amount"});

            if (! $held->isZero()) {
                $lines[] = JournalLineDraft::credit(Account::bySlug($slug)->id, $held, strtoupper($kind).' held from '.$advance->supplier->name.' (advance)', $advance->supplier);
            }
        }

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: LedgerRounding::balance($lines, $on, "Rounding on advance {$advance->number}"),
            memo: "Advance {$advance->number} to {$advance->supplier->name}".($advance->reference ? " ({$advance->reference})" : ''),
            source: $advance,
        ), $postedBy);
    }

    public function forApplication(SupplierAdvanceApplication $application, User $postedBy): ?Journal
    {
        if ($this->existingJournal($application) !== null) {
            return null;
        }

        $on = Carbon::parse($application->applied_on)->startOfDay();

        if (! $this->posting($on)) {
            return null;
        }

        $application->loadMissing(['supplier', 'bill', 'advance']);
        $amount = $application->money();
        $from = $application->advance?->number ?? 'on-account balance';

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit(AccountMapping::resolve('purchasing.accounts_payable', $on)->id, $amount, $application->bill->bill_number, $application->supplier),
                JournalLineDraft::credit($this->advances()->id, $amount, "{$from} applied", $application->supplier),
            ],
            memo: "Advance {$from} applied to bill {$application->bill->bill_number} ({$application->number})",
            source: $application,
        ), $postedBy);
    }

    public function forRefund(SupplierAdvanceRefund $refund, User $postedBy): ?Journal
    {
        if ($this->existingJournal($refund) !== null) {
            return null;
        }

        $on = Carbon::parse($refund->refunded_on)->startOfDay();

        if (! $this->posting($on)) {
            return null;
        }

        $refund->loadMissing(['supplier', 'moneyAccount', 'advance']);
        $amount = $refund->money();
        $from = $refund->advance?->number ?? 'on-account balance';

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($this->bankOf($refund->moneyAccount, $on)->id, $amount, $refund->moneyAccount->label(), null, $refund->moneyAccount),
                JournalLineDraft::credit($this->advances()->id, $amount, "Refund of {$from}", $refund->supplier),
            ],
            memo: "Refund {$refund->number} of {$from} from {$refund->supplier->name}".($refund->reference ? " ({$refund->reference})" : ''),
            source: $refund,
        ), $postedBy);
    }

    public function existingJournal(Model $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    private function posting(Carbon $on): bool
    {
        return AccountMapping::forKeyOn('inventory.stock_on_hand', $on) !== null;
    }

    private function advances(): Account
    {
        return Account::bySlug('supplier_advances')
            ?? throw MappingNotFound::for('account [supplier_advances]', Carbon::today());
    }

    /**
     * ⚠ The money account's own ledger head, never a mapping key, and refused
     * when it has none (the rule PostPurchaseJournals::forPayment states).
     */
    private function bankOf(?MoneyAccount $account, Carbon $on): Account
    {
        if ($account?->account_id === null) {
            throw MappingNotFound::for("money account [{$account?->name}]", $on);
        }

        return Account::findOrFail($account->account_id);
    }
}
