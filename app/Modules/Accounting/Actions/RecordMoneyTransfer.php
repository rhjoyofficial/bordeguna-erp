<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money moved between the business's own accounts.
 *
 *   Dr  the destination money account      the amount that ARRIVES
 *   Dr  the fee account                    what the move cost
 *       Cr  the source money account           amount + fee
 *
 * ⚠⚠ THE SOURCE IS REDUCED BY AMOUNT + FEE, NOT BY AMOUNT. A bKash cash-out of
 * 1,000 taka leaves 1,018.50 of the balance: the agent hands over 1,000 and the
 * charge is taken from the wallet. Modelling the fee as a deduction from the
 * arriving amount instead would make the destination short by the fee and leave
 * the source overstated - both wrong, and both invisible until somebody counted
 * the till.
 *
 * ⚠ NOTHING IS EARNED HERE. A transfer touches no income account at all. The
 * business is exactly as rich afterwards, minus the fee.
 */
class RecordMoneyTransfer
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $from,
        MoneyAccount $to,
        string $amount,
        mixed $transferredOn,
        User $createdBy,
        string $fee = '0',
        ?Account $feeAccount = null,
        ?string $reference = null,
        ?string $note = null,
        ?ClosedPeriodPass $pass = null,
        ?string $correctionOf = null,
    ): MoneyTransfer {
        $date = Carbon::parse($transferredOn)->startOfDay();

        // ⭐ Corrections wave 2 (24 Oct 2026): a Correct's new transfer may post
        // into a closed month only under the Owner's one-document pass, which
        // the correction service alone hands down.
        AccountingPeriod::assertOpenFor($date, $pass);

        if ($date->isFuture()) {
            throw ValidationException::withMessages([
                'transferred_on' => 'A transfer cannot be dated in the future.',
            ]);
        }

        $businessId = app(BusinessContext::class)->id();

        // ⚠⚠ SAME ACCOUNT BOTH SIDES IS A REFUSAL, NOT A HARMLESS NO-OP. The
        // journal would balance perfectly - debit and credit the same account -
        // and the ledger would carry a document that moved nothing while the
        // person who raised it believed money had moved.
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_money_account_id' => 'Money cannot be transferred to the account it came from.',
            ]);
        }

        foreach ([['from_money_account_id', $from], ['to_money_account_id', $to]] as [$field, $account]) {
            if ($account->business_id !== $businessId) {
                throw ValidationException::withMessages([
                    $field => 'That account belongs to a different business.',
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    $field => "{$account->name} is no longer active.",
                ]);
            }

            if (($refusal = $account->handRefusal()) !== null) {
                throw ValidationException::withMessages([$field => $refusal]);
            }

            // Without a chart mapping there is nothing to debit or credit.
            if ($account->account_id === null) {
                throw MappingNotFound::for("money account [{$account->name}]", $date);
            }
        }

        $moved = Money::ofTaka($amount);
        $charge = Money::ofTaka($fee);

        if ($moved->minor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A transfer needs an amount.',
            ]);
        }

        if ($charge->minor < 0) {
            throw ValidationException::withMessages([
                'fee' => 'A fee cannot be negative.',
            ]);
        }

        // ⚠ A fee with nowhere to go would silently unbalance the journal, so
        // the account is required exactly when there is something to charge.
        if ($charge->minor > 0) {
            if ($feeAccount === null) {
                throw ValidationException::withMessages([
                    'fee_account_id' => 'A transfer fee needs an expense account to charge it to.',
                ]);
            }

            if ($feeAccount->type !== AccountType::Expense) {
                throw ValidationException::withMessages([
                    'fee_account_id' => "{$feeAccount->label()} is not an expense account.",
                ]);
            }

            if (! $feeAccount->is_postable || ! $feeAccount->is_active) {
                throw ValidationException::withMessages([
                    'fee_account_id' => "{$feeAccount->label()} cannot be posted to.",
                ]);
            }
        }

        // Finance policy B14: no cash box or wallet below zero, a bank only to its overdraft.
        MoneyAvailability::assertCanPay($from, Money::sum([$moved, $charge]), $date, 'from_money_account_id');

        return DB::transaction(function () use (
            $from, $to, $moved, $charge, $feeAccount, $date, $createdBy, $reference, $note, $businessId, $pass, $correctionOf
        ) {
            // ⚠ claim(), not a bare next() (corrections wave 2): the number is
            // taken inside a savepoint, so a correction that rolls back later
            // uses up no number, and a collision retries with the next one.
            $transfer = DocumentNumber::claim(
                'transfer_number',
                fn () => $this->nextNumber($businessId, $date),
                fn (string $number) => MoneyTransfer::create([
                    'transfer_number' => $number,
                    'transferred_on' => $date,
                    'from_money_account_id' => $from->id,
                    'to_money_account_id' => $to->id,
                    'amount' => $moved->toTaka(),
                    'fee' => $charge->toTaka(),
                    'fee_account_id' => $charge->minor > 0 ? $feeAccount?->id : null,
                    'reference' => $reference,
                    'note' => $note,
                    'created_by' => $createdBy->id,
                ]),
            );

            $withdrawn = Money::sum([$moved, $charge]);

            $lines = [
                JournalLineDraft::debit($to->account_id, $moved, $to->label(), null, $to),
            ];

            if ($charge->minor > 0) {
                $lines[] = JournalLineDraft::debit(
                    $feeAccount->id,
                    $charge,
                    "Transfer fee - {$from->label()} to {$to->label()}",
                );
            }

            $lines[] = JournalLineDraft::credit($from->account_id, $withdrawn, $from->label(), null, $from);

            $lines = LedgerRounding::balance($lines, $date, "Rounding on transfer {$transfer->transfer_number}");

            $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: $lines,
                memo: "Money transfer {$transfer->transfer_number}".($correctionOf === null ? '' : " - Correction of {$correctionOf}")." - {$from->label()} to {$to->label()}",
                source: $transfer,
            ), $createdBy, pass: $pass);

            return $transfer->fresh(['from', 'to', 'feeAccount']);
        });
    }

    /**
     * `TRF-2026-27-0001`, restarting each fiscal year - the same shape as the
     * expense voucher, for the same reason: the unique index is the guarantee.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'TRF-'.FiscalYear::containing($date, $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(MoneyTransfer::query(), 'transfer_number', $prefix);
    }
}
