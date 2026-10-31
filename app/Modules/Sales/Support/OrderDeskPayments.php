<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Order desk (Joy, 31 Oct 2026): what the customer paid WITH the order,
 * any number of ways at once (bKash 500 + cash 300), typed on the order form
 * instead of a second visit to the order page.
 *
 * Each row is the order page's own payment (RecordOrderPayment): one receipt
 * per money account, held on the order, taken by its invoice when the goods
 * are billed, so the courier collects only the rest. Nothing new is posted
 * here that the order page could not already post.
 */
final class OrderDeskPayments
{
    /**
     * Checked before anything is written: the permission, and accounts that
     * a person may type money into (never a courier's or a gateway's own,
     * which fill only from what the courier or gateway reports).
     *
     * @param  list<array{money_account_id: int|string, amount: int|float|string, reference?: ?string}>  $rows
     * @return list<array{account: MoneyAccount, amount: string, reference: ?string}>
     */
    public static function check(array $rows, User $user): array
    {
        if ($rows === []) {
            return [];
        }

        if (! $user->can('sales.receipt.record')) {
            throw ValidationException::withMessages(['payments' => 'Taking a payment needs the receipt permission. Save the order without it; the office records it on the order page.']);
        }

        $checked = [];

        foreach ($rows as $index => $row) {
            $account = MoneyAccount::query()->findOrFail((int) $row['money_account_id']);

            if (in_array($account->type, [MoneyAccountType::Gateway, MoneyAccountType::Courier], true) && ! $account->isSslClearing()) {
                throw ValidationException::withMessages(["payments.{$index}.money_account_id" => "{$account->name} fills only from what the courier or gateway reports. Choose the cash, bank or wallet account the money went into."]);
            }

            $checked[] = [
                'account' => $account,
                'amount' => number_format((float) $row['amount'], 2, '.', ''),
                'reference' => isset($row['reference']) && trim((string) $row['reference']) !== '' ? trim((string) $row['reference']) : null,
            ];
        }

        return $checked;
    }

    /**
     * Inside the order's own transaction, dated the order's day.
     *
     * @param  list<array{account: MoneyAccount, amount: string, reference: ?string}>  $payments
     */
    public static function record(SalesOrder $order, array $payments, User $user): void
    {
        foreach ($payments as $index => $payment) {
            try {
                app(RecordOrderPayment::class)->handle(
                    order: $order->fresh(['lines']),
                    moneyAccount: $payment['account'],
                    receivedOn: $order->ordered_at->toDateString(),
                    amount: $payment['amount'],
                    createdBy: $user,
                    reference: $payment['reference'],
                    note: 'Paid with the order',
                );
            } catch (PeriodClosedException $e) {
                throw ValidationException::withMessages(['ordered_at' => $e->getMessage()]);
            } catch (MappingNotFound $e) {
                throw ValidationException::withMessages(["payments.{$index}.money_account_id" => MoneyAccountRefusal::sentence($payment['account'], $e)]);
            }
        }
    }
}
