<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ The journals of a customer advance's life (B11, 24 Oct 2026), the mirror
 * of PostSupplierAdvanceJournals on the liability side.
 *
 *   advance (receipt)     Dr the money account              Cr 2150 (party)
 *   advance (from_order)  Dr 1140 Accounts Receivable (p.)  Cr 2150 (party)
 *   advance (from_return) Dr 1140 Accounts Receivable (p.)  Cr 2150 (party)
 *   application           Dr 2150 (party)                   Cr 1140 (party)
 *   refund                Dr 2150 (party)                   Cr the money account
 *
 * ⚠ Same gate as every sales journal (PostSalesJournals): with no
 * `sales.revenue` mapping on the day the business is not posting sales yet,
 * and nothing is posted.
 */
class PostCustomerAdvanceJournals
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function forAdvance(CustomerAdvance $advance, User $postedBy): ?Journal
    {
        if ($this->existingJournal($advance) !== null) {
            return null;
        }

        $on = Carbon::parse($advance->received_on)->startOfDay();

        if (! self::posting($on)) {
            return null;
        }

        $advance->loadMissing(['customer', 'moneyAccount', 'salesOrder', 'salesReturn']);
        $total = $advance->money();

        $debit = match (true) {
            $advance->isFromOrder() => JournalLineDraft::debit(AccountMapping::resolve('sales.accounts_receivable', $on)->id, $total, "Order {$advance->salesOrder?->order_number} money moved to advance", $advance->customer),
            // ⭐ Integration (25 Oct 2026): a return's open credit kept as advance.
            $advance->isFromReturn() => JournalLineDraft::debit(AccountMapping::resolve('sales.accounts_receivable', $on)->id, $total, "Return {$advance->salesReturn?->return_number} credit kept as advance", $advance->customer),
            default => JournalLineDraft::debit($this->bankOf($advance->moneyAccount, $on)->id, $total, $advance->moneyAccount->label(), null, $advance->moneyAccount),
        };

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                $debit,
                JournalLineDraft::credit($this->advances($on)->id, $total, $advance->reference ?? $advance->number, $advance->customer),
            ],
            memo: match (true) {
                $advance->isFromOrder() => "Advance {$advance->number} from order {$advance->salesOrder?->order_number}",
                $advance->isFromReturn() => "Advance {$advance->number} from return {$advance->salesReturn?->return_number}",
                default => "Advance {$advance->number} received",
            }
            ." - {$advance->customer->name}".($advance->reference ? " ({$advance->reference})" : ''),
            source: $advance,
        ), $postedBy);
    }

    public function forApplication(CustomerAdvanceApplication $application, User $postedBy): ?Journal
    {
        if ($this->existingJournal($application) !== null) {
            return null;
        }

        $on = Carbon::parse($application->applied_on)->startOfDay();

        if (! self::posting($on)) {
            return null;
        }

        $application->loadMissing(['customer', 'invoice', 'advance']);
        $amount = $application->money();
        $from = $application->advance?->number ?? 'on-account balance';

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($this->advances($on)->id, $amount, "{$from} used", $application->customer),
                JournalLineDraft::credit(AccountMapping::resolve('sales.accounts_receivable', $on)->id, $amount, $application->invoice->invoice_number, $application->customer),
            ],
            memo: "Advance {$from} used on invoice {$application->invoice->invoice_number} ({$application->number})",
            source: $application,
        ), $postedBy);
    }

    public function forRefund(CustomerAdvanceRefund $refund, User $postedBy): ?Journal
    {
        if ($this->existingJournal($refund) !== null) {
            return null;
        }

        $on = Carbon::parse($refund->refunded_on)->startOfDay();

        if (! self::posting($on)) {
            return null;
        }

        $refund->loadMissing(['customer', 'moneyAccount', 'advance']);
        $amount = $refund->money();
        $from = $refund->advance?->number ?? 'on-account balance';

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($this->advances($on)->id, $amount, "Refund of {$from}", $refund->customer),
                JournalLineDraft::credit($this->bankOf($refund->moneyAccount, $on)->id, $amount, $refund->moneyAccount->label(), null, $refund->moneyAccount),
            ],
            memo: "Refund {$refund->number} of {$from} to {$refund->customer->name}".($refund->reference ? " ({$refund->reference})" : ''),
            source: $refund,
        ), $postedBy);
    }

    /** The document's own journal (not a reversal), or null. */
    public function existingJournal(Model $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    /** Whether the business posts sales on that day. */
    public static function posting(Carbon $on): bool
    {
        return AccountMapping::forKeyOn('sales.revenue', $on) !== null;
    }

    private function advances(Carbon $on): Account
    {
        return Account::bySlug('customer_advances')
            ?? throw MappingNotFound::for('account [customer_advances]', $on);
    }

    /** ⚠ The money account's own ledger head, refused when it has none. */
    private function bankOf(?MoneyAccount $account, Carbon $on): Account
    {
        if ($account?->account_id === null) {
            throw MappingNotFound::for("money account [{$account?->name}]", $on);
        }

        return Account::findOrFail($account->account_id);
    }
}
