<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Support\SupplierCreditBalance;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The supplier pays back what they owe us on Accounts Payable (ruling P5,
 * "Payments and refunds", 19 Oct 2026):
 *
 *   Dr the money account   Cr 2110 Accounts Payable (party = supplier)
 *
 * ⚠⚠ CAPPED AT THE DEBIT BALANCE, on the refund date AND today, re-read
 * under the supplier's lock inside the transaction, so two refunds at once
 * cannot both take the last of it and a refund can never turn into a payable
 * nobody billed. What is in 1160 Advances is an ADVANCE and is refunded as
 * one (RefundSupplierAdvance, SARF); a supplier credit note is never touched
 * here, so no amount is relieved twice.
 */
class RecordSupplierRefund
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Supplier $supplier,
        MoneyAccount $moneyAccount,
        mixed $receivedOn,
        string $amount,
        User $by,
        ?string $reference = null,
        ?string $note = null,
    ): SupplierRefund {
        $on = Carbon::parse($receivedOn)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['received_on' => 'A refund cannot be dated in the future.']);
        }

        AccountingPeriod::assertOpenFor($on);

        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount the supplier paid back.']);
        }

        RecordSupplierAdvance::assertMoneyAccount($moneyAccount, $supplier);

        return DB::transaction(function () use ($supplier, $moneyAccount, $on, $money, $by, $reference, $note) {
            $supplier = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            // On the refund date, and today: a later payment may already have
            // used the balance a back-dated refund would claim.
            $open = Money::ofMinor(min(
                SupplierCreditBalance::of($supplier, $on)->minor,
                SupplierCreditBalance::of($supplier)->minor,
            ));

            if ($money->minor > $open->minor) {
                throw ValidationException::withMessages([
                    'amount' => $open->isZero()
                        ? "{$supplier->name} owes nothing back on their account. Money they still hold as an advance is refunded as an advance."
                        : "{$supplier->name} owes back only {$open->format()} on their account.",
                ]);
            }

            $refund = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(SupplierRefund::query(), 'number', RecordSupplierAdvance::prefix('SREF', $supplier, $on)),
                fn (string $number) => SupplierRefund::create([
                    'business_id' => $supplier->business_id,
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'money_account_id' => $moneyAccount->id,
                    'received_on' => $on->toDateString(),
                    'amount' => $money->toTaka(),
                    'reference' => $reference,
                    'note' => $note,
                    'created_by' => $by->id,
                ]),
            );

            $this->post($refund->fresh(['supplier', 'moneyAccount']), $by);

            return $refund->fresh(['supplier', 'moneyAccount']);
        });
    }

    public static function journalOf(SupplierRefund $refund): ?Journal
    {
        return Journal::query()
            ->where('source_type', $refund->getMorphClass())
            ->where('source_id', $refund->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    private function post(SupplierRefund $refund, User $by): Journal
    {
        $on = Carbon::parse($refund->received_on->toDateString())->startOfDay();
        $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);

        if ($refund->moneyAccount?->account_id === null) {
            throw MappingNotFound::for("money account [{$refund->moneyAccount?->name}]", $on);
        }

        $bank = Account::findOrFail($refund->moneyAccount->account_id);
        $amount = $refund->money();

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($bank->id, $amount, $refund->moneyAccount->label(), null, $refund->moneyAccount),
                JournalLineDraft::credit($payable->id, $amount, "Refund {$refund->number}", $refund->supplier),
            ],
            memo: "Refund {$refund->number} from {$refund->supplier->name} of what they owed us".($refund->reference ? " ({$refund->reference})" : ''),
            source: $refund,
        ), $by);
    }
}
