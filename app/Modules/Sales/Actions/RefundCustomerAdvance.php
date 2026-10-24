<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use App\Modules\Sales\Support\CustomerAdvanceBook;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A customer's advance paid back (B11, 24 Oct 2026): CARF-..., Dr 2150
 * (party = customer) / Cr the money account.
 *
 * ⚠⚠ CAPPED BY WHAT IS STILL OPEN on that advance (or on the customer's
 * on-account balance), re-read under the customer's lock: an uncapped refund
 * is how a liability goes quietly negative. Finance policy B14 applies: no
 * cash box or wallet below zero, a bank only to its overdraft.
 */
class RefundCustomerAdvance
{
    public function __construct(private readonly PostCustomerAdvanceJournals $posting) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        ?CustomerAdvance $advance,
        MoneyAccount $moneyAccount,
        mixed $refundedOn,
        string $amount,
        User $by,
        ?string $reference = null,
        ?string $note = null,
    ): CustomerAdvanceRefund {
        $date = RecordCustomerAdvance::date($refundedOn, 'refunded_on');
        $money = RecordCustomerAdvance::amount($amount);

        RecordCustomerAdvance::assertMoneyAccount($moneyAccount, $customer);
        MoneyAvailability::assertCanPay($moneyAccount, $money, $date, 'money_account_id');

        return DB::transaction(function () use ($customer, $advance, $moneyAccount, $date, $money, $by, $reference, $note) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($advance !== null) {
                $advance = CustomerAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();

                if ($advance->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['amount' => "Advance {$advance->number} belongs to a different customer."]);
                }

                if ($date->lt($advance->received_on->copy()->startOfDay())) {
                    throw ValidationException::withMessages(['refunded_on' => "Advance {$advance->number} was received after that date."]);
                }
            }

            $open = $advance === null ? CustomerAdvanceBook::onAccount($customer) : $advance->open();
            $name = $advance?->number ?? 'The on-account balance';

            if ($money->minor > $open->minor) {
                throw ValidationException::withMessages(['amount' => "{$name} has only ".Money::ofMinor(max(0, $open->minor))->format().' left to pay back.']);
            }

            $prefix = RecordCustomerAdvance::prefix('CARF', (int) $customer->business_id, $date);

            $refund = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(CustomerAdvanceRefund::query(), 'number', $prefix),
                fn (string $number) => CustomerAdvanceRefund::create([
                    'business_id' => $customer->business_id,
                    'number' => $number,
                    'customer_id' => $customer->id,
                    'customer_advance_id' => $advance?->id,
                    'money_account_id' => $moneyAccount->id,
                    'refunded_on' => $date->toDateString(),
                    'amount' => $money->toTaka(),
                    'reference' => trim((string) $reference) ?: null,
                    'source_note' => $advance === null ? CustomerAdvanceBook::onAccountSourceNote($customer, $money) : null,
                    'note' => trim((string) $note) ?: null,
                    'created_by' => $by->id,
                ]),
            );

            $this->posting->forRefund($refund, $by);

            return $refund->fresh(['customer', 'moneyAccount', 'advance']);
        });
    }
}
