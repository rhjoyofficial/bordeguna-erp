<?php

namespace App\Modules\Sales\Actions\Commission;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CommissionPayment;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Support\CommissionHeads;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Pay commission a settlement left owed (Joy's Q18, 25 Oct 2026):
 * Dr Commission Payable (the person) / Cr the money account. Never more than
 * is still owed on that settlement, re-read under a lock on the settlement,
 * so a double click pays once. Reverse is the corrections engine's.
 */
class PayCommission
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(CommissionSettlement $settlement, string $paidOn, string $amount, MoneyAccount $moneyAccount, ?string $note, User $by): CommissionPayment
    {
        $on = Carbon::parse($paidOn)->startOfDay();
        $money = Money::ofTaka($amount);

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['paid_on' => 'A payment cannot be dated in the future.']);
        }

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Type the amount paid.']);
        }

        AccountingPeriod::assertOpenFor($on);

        return DocumentNumber::claim('number', fn () => $this->nextNumber((int) $settlement->business_id), function (string $number) use ($settlement, $on, $money, $moneyAccount, $note, $by) {
            /** @var CommissionSettlement $settlement */
            $settlement = CommissionSettlement::query()->whereKey($settlement->id)->lockForUpdate()
                ->with(['agreement.employee', 'agreement.customer', 'agreement.supplier'])->firstOrFail();

            if ($settlement->method !== 'payable' || ! $settlement->isPosted()) {
                throw ValidationException::withMessages(['amount' => "{$settlement->number} has nothing owed to pay."]);
            }

            if ($on->lt($settlement->settled_on)) {
                throw ValidationException::withMessages(['paid_on' => "{$settlement->number} was settled on {$settlement->settled_on->format('d/m/Y')}; pay it on or after that day."]);
            }

            $owed = $settlement->owed();

            if ($money->minor > $owed->minor) {
                throw ValidationException::withMessages(['amount' => "Only Tk {$owed->format()} is still owed on {$settlement->number}."]);
            }

            MoneyAvailability::assertCanPay($moneyAccount, $money, $on, 'money_account_id');

            $agreement = $settlement->agreement;
            $party = $agreement->party();

            $payment = CommissionPayment::create([
                'business_id' => $settlement->business_id,
                'number' => $number,
                'commission_settlement_id' => $settlement->id,
                'paid_on' => $on,
                'amount' => $money->toTaka(),
                'money_account_id' => $moneyAccount->id,
                'note' => $note,
                'created_by' => $by->id,
            ]);

            $memo = "Commission paid {$number} for {$settlement->number} to {$agreement->partyName()}";
            $journal = $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: [
                    JournalLineDraft::debit(CommissionHeads::payable()->id, $money, $memo, $party),
                    JournalLineDraft::credit($moneyAccount->account_id, $money, $moneyAccount->label(), $party, $moneyAccount),
                ],
                memo: $memo,
                source: $payment,
            ), $by);

            $payment->forceFill(['journal_id' => $journal->id])->save();

            return $payment;
        });
    }

    private function nextNumber(int $businessId): string
    {
        $count = CommissionPayment::query()->withoutGlobalScopes()->where('business_id', $businessId)->count();

        return 'CP-'.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
