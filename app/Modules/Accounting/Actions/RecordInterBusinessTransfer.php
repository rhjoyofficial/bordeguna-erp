<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money from this business to another of the group (finance decision C2).
 *
 * ⚠⚠ TWO JOURNALS, ONE TRANSACTION: the sender's in its own books, the
 * receiver's in the receiver's books. Either both exist or neither does, so the
 * Due-from and Due-to always cancel across the group.
 */
class RecordInterBusinessTransfer
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @throws ValidationException
     */
    public function handle(MoneyAccount $from, MoneyAccount $to, string $amount, mixed $date, ?string $note, User $by): InterBusinessTransfer
    {
        $senderId = (int) app(BusinessContext::class)->id();
        $on = Carbon::parse($date)->startOfDay();
        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount moved.']);
        }

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['transferred_on' => 'A transfer cannot be dated in the future.']);
        }

        if ((int) $from->business_id !== $senderId) {
            throw ValidationException::withMessages(['from_money_account_id' => 'Send from one of this business\'s own accounts.']);
        }

        if ((int) $to->business_id === $senderId) {
            throw ValidationException::withMessages(['to_money_account_id' => 'Moving money inside one business is a Contra voucher.']);
        }

        if (! $by->businesses()->whereKey($to->business_id)->exists()) {
            throw ValidationException::withMessages(['to_money_account_id' => 'You do not work in the receiving business.']);
        }

        foreach (['from_money_account_id' => $from, 'to_money_account_id' => $to] as $field => $account) {
            if (! $account->is_active || $account->isCourier() || $account->isGateway() || $account->account_id === null) {
                throw ValidationException::withMessages([$field => "{$account->name} cannot take part in a transfer."]);
            }
        }

        $receiver = Business::withoutGlobalScopes()->findOrFail($to->business_id);
        $sender = Business::withoutGlobalScopes()->findOrFail($senderId);

        AccountingPeriod::assertOpenFor($on);
        MoneyAvailability::assertCanPay($from, $money, $on, 'from_money_account_id');

        $dueFrom = Account::bySlug('due_from_businesses')->id;
        $dueTo = Account::bySlug('due_to_businesses')->id;
        $memo = trim("To {$receiver->name}".($note ? ": {$note}" : ''));

        return DB::transaction(function () use ($from, $to, $money, $on, $note, $by, $senderId, $sender, $receiver, $dueFrom, $dueTo, $memo) {
            $transfer = InterBusinessTransfer::create([
                'from_business_id' => $senderId,
                'to_business_id' => $receiver->id,
                'from_money_account_id' => $from->id,
                'to_money_account_id' => $to->id,
                'transferred_on' => $on,
                'amount' => $money->toTaka(),
                'note' => $note,
                'created_by' => $by->id,
            ]);

            $this->postJournal->handle(new JournalDraft(date: $on, lines: [
                JournalLineDraft::debit($dueFrom, $money, $memo, $receiver),
                JournalLineDraft::credit($from->account_id, $money, $from->label(), null, $from),
            ], memo: $memo, source: $transfer), $by);

            // The receiver's books, in the receiver's context, then back.
            TenantScope::enter($receiver->id);

            try {
                AccountingPeriod::assertOpenFor($on);
                $this->postJournal->handle(new JournalDraft(date: $on, lines: [
                    JournalLineDraft::debit($to->account_id, $money, $to->label(), null, $to),
                    JournalLineDraft::credit($dueTo, $money, trim("From {$sender->name}".($note ? ": {$note}" : '')), $sender),
                ], memo: "From {$sender->name}", source: $transfer), $by);
            } finally {
                TenantScope::enter($senderId);
            }

            return $transfer;
        });
    }
}
