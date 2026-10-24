<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAdvance;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ A customer's money kept as their advance (Joy's initial plan R90, B11,
 * 24 Oct 2026). "A dealer's advance is held in a liability account and
 * deducted when we invoice him."
 *
 *   receive()    money in with no invoice and no order behind it (Sales >
 *                Receive payment, "keep as advance"; Sales > Advances):
 *                Dr the money account / Cr 2150 (party = customer)
 *   fromOrder()  what a cancelled order still holds, moved to the customer's
 *                advance instead of being paid back (gap S6): Dr 1140 / Cr 2150
 *   fromReturn() a sales return's open credit kept for the customer (IP14's
 *                "Keep as open credit", integration 25 Oct 2026): Dr 1140 /
 *                Cr 2150, capped by the return's refundable() under its lock
 *
 * ⚠ Same money-account rules as a customer receipt (RecordCustomerReceipt),
 * on purpose: a courier's or a gateway's account fills itself and is refused
 * here, a switched-off account too. Closed month refused, future date refused.
 */
class RecordCustomerAdvance
{
    public function __construct(private readonly PostCustomerAdvanceJournals $posting) {}

    /**
     * @throws ValidationException
     */
    public function receive(
        Customer $customer,
        MoneyAccount $moneyAccount,
        mixed $receivedOn,
        string $amount,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
        ?CustomerReceipt $withReceipt = null,
    ): CustomerAdvance {
        $date = self::date($receivedOn, 'received_on');
        $money = self::amount($amount);

        self::assertMoneyAccount($moneyAccount, $customer);

        if (! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => "{$customer->name} is switched off."]);
        }

        return DB::transaction(function () use ($customer, $moneyAccount, $date, $money, $createdBy, $reference, $note, $withReceipt) {
            // Queue behind anyone else using this customer's advances.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first(['id']);

            $advance = $this->create($customer, $date, [
                'kind' => CustomerAdvance::RECEIPT,
                'money_account_id' => $moneyAccount->id,
                'customer_receipt_id' => $withReceipt?->id,
                'amount' => $money->toTaka(),
                'reference' => self::text($reference),
                'note' => self::text($note),
                'created_by' => $createdBy->id,
            ]);

            $this->posting->forAdvance($advance, $createdBy);

            return $advance->fresh(['customer', 'moneyAccount']);
        });
    }

    /**
     * ⚠⚠ CAPPED BY WHAT THE ORDER STILL HOLDS, re-read under the order's lock
     * (the cap RecordCustomerRefund::forOrder keeps, for the same reason: two
     * clerks must not both move, or move and refund, the same money).
     *
     * @throws ValidationException
     */
    public function fromOrder(SalesOrder $order, mixed $on, User $createdBy, ?string $amount = null, ?string $note = null): CustomerAdvance
    {
        $date = self::date($on, 'moved_on');

        return DB::transaction(function () use ($order, $date, $createdBy, $amount, $note) {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->with('customer')->firstOrFail();

            if (! $order->isFinishedForBilling()) {
                throw ValidationException::withMessages([
                    'amount' => "Order {$order->order_number} is still open. Its money stays with it until it is cancelled or fully billed.",
                ]);
            }

            $held = $order->advanceHeld();
            $money = $amount === null || trim($amount) === '' ? $held : self::amount($amount);

            if ($held->minor <= 0) {
                throw ValidationException::withMessages(['amount' => "Order {$order->order_number} holds none of the customer's money."]);
            }

            if ($money->minor <= 0 || $money->minor > $held->minor) {
                throw ValidationException::withMessages(['amount' => "Order {$order->order_number} holds only {$held->format()} of the customer's money."]);
            }

            $last = $order->advanceReceipts()->whereNull('reversed_at')->max('received_on');

            if ($last !== null && $date->lt(Carbon::parse($last)->startOfDay())) {
                throw ValidationException::withMessages(['moved_on' => 'Move the money on or after the day it was received ('.Carbon::parse($last)->format('d/m/Y').').']);
            }

            Customer::query()->whereKey($order->customer_id)->lockForUpdate()->first(['id']);

            $advance = $this->create($order->customer, $date, [
                'kind' => CustomerAdvance::FROM_ORDER,
                'sales_order_id' => $order->id,
                'amount' => $money->toTaka(),
                'reference' => $order->order_number,
                'note' => self::text($note) ?? "Moved from order {$order->order_number}",
                'created_by' => $createdBy->id,
            ]);

            $this->posting->forAdvance($advance, $createdBy);

            return $advance->fresh(['customer', 'salesOrder']);
        });
    }

    /**
     * ⭐ IP3 meets IP14 (integration, 25 Oct 2026): what a return left open
     * (credit not used on an invoice nor paid back) becomes the customer's
     * advance, which their next invoices take (ApplyCustomerAdvances).
     *
     * ⚠⚠ CAPPED BY refundable(), re-read under the return's lock: the same
     * figure caps a refund and "Use on an invoice" (ApplySalesReturnCredit),
     * and an advance is subtracted from it, so one taka is spent once.
     *
     * @param  string|null  $openSeen  the open credit the page showed: a stale page is refused
     *
     * @throws ValidationException
     */
    public function fromReturn(SalesReturn $return, mixed $on, User $createdBy, ?string $amount = null, ?string $openSeen = null): CustomerAdvance
    {
        $date = self::date($on, 'moved_on');

        return DB::transaction(function () use ($return, $date, $createdBy, $amount, $openSeen) {
            /** @var SalesReturn $return */
            $return = SalesReturn::query()->whereKey($return->id)->lockForUpdate()->with('customer')->firstOrFail();

            if ($return->isReversed() || $return->isMirror()) {
                throw ValidationException::withMessages(['amount' => "{$return->return_number} has been reversed."]);
            }

            if ($return->customer === null || $return->customer->isWalkIn()) {
                throw ValidationException::withMessages(['amount' => 'A walk-in customer has no account to keep an advance on. Pay the credit back instead.']);
            }

            $open = $return->refundable();

            if ($openSeen !== null && Money::ofTaka($openSeen)->minor !== $open->minor) {
                throw ValidationException::withMessages([
                    'amount' => "The open credit on {$return->return_number} is now ".$open->toTaka().' (it changed since the page opened). Check and try again.',
                ]);
            }

            $money = $amount === null || trim($amount) === '' ? $open : self::amount($amount);

            if ($open->minor <= 0) {
                throw ValidationException::withMessages(['amount' => "{$return->return_number} has no open credit left."]);
            }

            if ($money->minor <= 0 || $money->minor > $open->minor) {
                throw ValidationException::withMessages(['amount' => "Only {$open->toTaka()} of {$return->return_number} is still open."]);
            }

            if ($date->lt($return->returned_at->copy()->startOfDay())) {
                throw ValidationException::withMessages(['moved_on' => 'Keep the credit on or after the day of the return ('.$return->returned_at->format('d/m/Y').').']);
            }

            Customer::query()->whereKey($return->customer_id)->lockForUpdate()->first(['id']);

            $advance = $this->create($return->customer, $date, [
                'kind' => CustomerAdvance::FROM_RETURN,
                'sales_return_id' => $return->id,
                'amount' => $money->toTaka(),
                'reference' => $return->return_number,
                'note' => "Open credit of return {$return->return_number}",
                'created_by' => $createdBy->id,
            ]);

            $this->posting->forAdvance($advance, $createdBy);

            return $advance->fresh(['customer', 'salesReturn']);
        });
    }

    /**
     * CADV-2026-27-0001, the business's fiscal year in the middle.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(Customer $customer, Carbon $on, array $attributes): CustomerAdvance
    {
        $prefix = self::prefix('CADV', (int) $customer->business_id, $on);

        return DocumentNumber::claim(
            'number',
            fn () => DocumentNumber::next(CustomerAdvance::query(), 'number', $prefix),
            fn (string $number) => CustomerAdvance::create([
                'business_id' => $customer->business_id,
                'number' => $number,
                'customer_id' => $customer->id,
                'received_on' => $on->toDateString(),
                ...$attributes,
            ]),
        );
    }

    /** "CADV-2026-27-": the document's letters and the fiscal year of `$on`. */
    public static function prefix(string $letters, int $businessId, Carbon $on): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);

        return $letters.'-'.FiscalYear::containing($on, $business)->label().'-';
    }

    /**
     * The money-account rules every customer receipt follows.
     *
     * @throws ValidationException
     */
    public static function assertMoneyAccount(MoneyAccount $moneyAccount, Customer $customer, string $field = 'money_account_id'): void
    {
        if ($moneyAccount->business_id !== $customer->business_id) {
            throw ValidationException::withMessages([$field => 'That account belongs to a different business.']);
        }

        if ($moneyAccount->isCourier() || $moneyAccount->isGateway()) {
            throw ValidationException::withMessages([$field => "{$moneyAccount->name} is filled by its parcels or payments, never by hand. Choose a bank, cash or mobile wallet account."]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([$field => 'That account is no longer active.']);
        }

        if ($moneyAccount->account_id === null) {
            throw ValidationException::withMessages([$field => "{$moneyAccount->name} has no ledger account yet."]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function date(mixed $value, string $field): Carbon
    {
        $date = Carbon::parse($value ?? 'today')->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages([$field => 'That date has not happened yet.']);
        }

        AccountingPeriod::assertOpenFor($date);

        return $date;
    }

    /**
     * @throws ValidationException
     */
    public static function amount(string $amount): Money
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount in taka (for example 5000 or 5000.50).']);
        }

        $money = Money::ofTaka(trim($amount));

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount.']);
        }

        return $money;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
