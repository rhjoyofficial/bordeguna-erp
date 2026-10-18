<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use App\Modules\Purchasing\Support\SupplierCreditBalance;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ TALLY'S BILL-WISE DETAILS ON A VOUCHER (Joy, 18 Oct 2026: "how do I give
 * them money or how do they give me?").
 *
 * A supplier or customer picked on a Payment, Receipt or Journal voucher is
 * their ledger under Sundry Creditors / Sundry Debtors. What the money is
 * AGAINST is listed here, per voucher type, and every voucher line names how
 * much goes to each (Agst Ref), the rest being an Advance where one is
 * allowed. Nothing is posted here: PostVoucher turns the allocation into the
 * existing documents (payment, receipt, refunds, adjustment).
 *
 *   supplier on Payment   open bills          rest = advance (1160)
 *   customer on Receipt   open invoices       rest = advance (2150)
 *   supplier on Receipt   what they owe us back (debit balance on 2110, SREF),
 *                         then their open advances (SARF)       no rest
 *   customer on Payment   their advances (2150), returns with credit to
 *                         refund, orders holding their money    no rest
 *   either on Journal     open invoices / bills (party adjustment)  no rest
 *
 * ⚠ Data stays one control account per side (2110 / 1140) with the party on
 * the line; no ledger per party is ever created in the chart.
 */
final class PartyBillWise
{
    public const SUPPLIER_PAYMENT = 'supplier_payment';

    public const CUSTOMER_RECEIPT = 'customer_receipt';

    public const SUPPLIER_REFUND = 'supplier_refund';

    public const CUSTOMER_REFUND = 'customer_refund';

    public const ADJUSTMENT = 'adjustment';

    public const EMPLOYEE = 'employee';

    /** What a party line on this voucher type does, or null when the pair makes no sense. */
    public static function mode(VoucherType $type, Model $party): ?string
    {
        return match (true) {
            $party instanceof Employee => self::EMPLOYEE,
            $type === VoucherType::Journal && ($party instanceof Customer || $party instanceof Supplier) => self::ADJUSTMENT,
            $party instanceof Supplier && $type === VoucherType::Payment => self::SUPPLIER_PAYMENT,
            $party instanceof Customer && $type === VoucherType::Receipt => self::CUSTOMER_RECEIPT,
            $party instanceof Supplier && $type === VoucherType::Receipt => self::SUPPLIER_REFUND,
            $party instanceof Customer && $type === VoucherType::Payment => self::CUSTOMER_REFUND,
            default => null,
        };
    }

    /** Whether what no bill takes may stay with the party as an advance. */
    public static function restIsAdvance(string $mode): bool
    {
        return in_array($mode, [self::SUPPLIER_PAYMENT, self::CUSTOMER_RECEIPT], true);
    }

    /**
     * Everything open that a line for this party can be allocated to, in the
     * default order (oldest first; for a supplier refund what they owe us back
     * first). Only documents dated on or before the voucher (audit 13 Oct).
     *
     * @return list<array{ref: string, kind: string, id: int|null, number: string, date: string|null, due_date: string|null, open: Money, document: Model|null}>
     */
    public static function items(string $mode, Model $party, Carbon $date): array
    {
        return match ($mode) {
            self::SUPPLIER_PAYMENT => self::bills($party, $date),
            self::CUSTOMER_RECEIPT => self::invoices($party, $date),
            self::ADJUSTMENT => $party instanceof Customer ? self::invoices($party, $date) : self::bills($party, $date),
            self::SUPPLIER_REFUND => self::supplierRefundables($party, $date),
            self::CUSTOMER_REFUND => self::customerRefundables($party, $date),
            default => [],
        };
    }

    /**
     * Shares a line's amount out over the open items.
     *
     * `$given` null is "oldest first" (every voucher before 20 Oct 2026). A
     * list of {ref, amount} is what the person typed: each ref must be open
     * for this party and no amount may pass what is still open after the
     * voucher's earlier lines (`$planned`, paisa keyed by ref).
     *
     * @param  list<array<string, mixed>>  $items  from items()
     * @param  list<array{ref?: mixed, amount?: mixed}>|null  $given
     * @param  array<string, int>  $planned
     * @return array{allocations: list<array{item: array<string, mixed>, minor: int}>, rest: int}
     *
     * @throws ValidationException
     */
    public static function allocate(array $items, ?array $given, int $amountMinor, array &$planned, string $field): array
    {
        $byRef = [];

        foreach ($items as $item) {
            $byRef[$item['ref']] = $item;
        }

        $allocations = [];
        $left = $amountMinor;

        if ($given === null) {
            foreach ($items as $item) {
                if ($left <= 0) {
                    break;
                }

                $open = $item['open']->minor - ($planned[$item['ref']] ?? 0);

                if ($open <= 0) {
                    continue;
                }

                $take = min($open, $left);
                $planned[$item['ref']] = ($planned[$item['ref']] ?? 0) + $take;
                $left -= $take;
                $allocations[] = ['item' => $item, 'minor' => $take];
            }

            return ['allocations' => $allocations, 'rest' => $left];
        }

        foreach ($given as $entry) {
            $ref = (string) ($entry['ref'] ?? '');
            $minor = Money::ofTaka((string) ($entry['amount'] ?? '0'))->minor;

            // The advance row is the rest, whatever it says: never an item.
            if ($ref === 'advance' || $minor === 0) {
                continue;
            }

            if ($minor < 0) {
                throw ValidationException::withMessages([$field => 'A bill-wise amount cannot be negative.']);
            }

            $item = $byRef[$ref] ?? null;

            if ($item === null) {
                throw ValidationException::withMessages([$field => 'A bill chosen is no longer open for this party, or is dated after the voucher. Open the bill-wise details again.']);
            }

            $open = $item['open']->minor - ($planned[$ref] ?? 0);

            if ($minor > $open) {
                throw ValidationException::withMessages([$field => "{$item['number']} has only ".Money::ofMinor(max(0, $open))->format().' open.']);
            }

            $planned[$ref] = ($planned[$ref] ?? 0) + $minor;
            $left -= $minor;
            $allocations[] = ['item' => $item, 'minor' => $minor];
        }

        if ($left < 0) {
            throw ValidationException::withMessages([$field => 'The bill-wise amounts come to more than the line. Make the line amount match.']);
        }

        return ['allocations' => $allocations, 'rest' => $left];
    }

    /**
     * Items as the voucher form shows them.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function present(array $items): array
    {
        return array_map(fn (array $item) => [
            'ref' => $item['ref'],
            'kind' => $item['kind'],
            'number' => $item['number'],
            'date' => $item['date'],
            'due_date' => $item['due_date'],
            'open' => $item['open']->toTaka(),
            'open_label' => $item['open']->format(),
        ], $items);
    }

    // ─── What is open ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function bills(Supplier $supplier, Carbon $date): array
    {
        return SupplierBill::query()->where('supplier_id', $supplier->id)->whereDate('bill_date', '<=', $date->toDateString())
            ->orderBy('bill_date')->orderBy('id')->get()
            ->map(fn (SupplierBill $bill) => self::item('bill', $bill, $bill->bill_number, $bill->bill_date, $bill->due_date ?? null, $bill->outstanding()))
            ->filter(fn (array $item) => $item['open']->minor > 0)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function invoices(Customer $customer, Carbon $date): array
    {
        return SalesInvoice::query()->where('customer_id', $customer->id)->whereDate('invoice_date', '<=', $date->toDateString())
            ->orderBy('invoice_date')->orderBy('id')->get()
            ->map(fn (SalesInvoice $invoice) => self::item('invoice', $invoice, $invoice->invoice_number, $invoice->invoice_date, $invoice->due_date ?? null, $invoice->outstanding()))
            ->filter(fn (array $item) => $item['open']->minor > 0)->values()->all();
    }

    /**
     * What a supplier can pay back: the DEBIT balance on Accounts Payable
     * (SREF, ruling P5), then each open advance and the on-account balance
     * (SARF, ruling P2). Both caps are the engines' own.
     *
     * @return list<array<string, mixed>>
     */
    private static function supplierRefundables(Supplier $supplier, Carbon $date): array
    {
        $items = [];
        $owed = Money::ofMinor(min(SupplierCreditBalance::of($supplier, $date)->minor, SupplierCreditBalance::of($supplier)->minor));

        if ($owed->minor > 0) {
            $items[] = ['ref' => 'credit_balance', 'kind' => 'credit_balance', 'id' => null, 'number' => 'Owed back on account', 'date' => null, 'due_date' => null, 'open' => $owed, 'document' => null];
        }

        foreach (SupplierAdvanceBook::available($supplier) as $row) {
            if ($row['open']->minor <= 0 || ($row['advance'] !== null && $row['advance']->paid_on->copy()->startOfDay()->gt($date))) {
                continue;
            }

            $items[] = [
                'ref' => SupplierAdvanceBook::keyOf($row['advance']),
                'kind' => 'advance',
                'id' => $row['advance']?->id,
                'number' => $row['advance'] === null ? 'Advance on account' : "Advance {$row['number']}",
                'date' => $row['date'],
                'due_date' => null,
                'open' => $row['open'],
                'document' => $row['advance'],
            ];
        }

        return $items;
    }

    /**
     * What can go back to a customer: what they hold with us as an advance
     * (2150, from earlier Receipt vouchers), then the credit of each sales
     * return not yet refunded, then each order holding their money (C-SR-6).
     *
     * @return list<array<string, mixed>>
     */
    private static function customerRefundables(Customer $customer, Carbon $date): array
    {
        $items = [];
        // On the voucher date and today, as the supplier refund caps are: a
        // later receipt may already have used what a back-dated one would claim.
        $held = Money::ofMinor(min(self::customerAdvance($customer, $date)->minor, self::customerAdvance($customer)->minor));

        if ($held->minor > 0) {
            $items[] = ['ref' => 'customer_advance', 'kind' => 'customer_advance', 'id' => null, 'number' => 'Advance held', 'date' => null, 'due_date' => null, 'open' => $held, 'document' => null];
        }

        foreach (SalesReturn::query()->where('customer_id', $customer->id)->whereDate('returned_at', '<=', $date->toDateString())->orderBy('returned_at')->orderBy('id')->get() as $return) {
            $open = $return->refundable();

            if ($open->minor > 0) {
                $items[] = self::item('return', $return, $return->return_number, $return->returned_at, null, $open);
            }
        }

        foreach (SalesOrder::query()->where('customer_id', $customer->id)->whereHas('advanceReceipts')->orderBy('id')->get() as $order) {
            $open = $order->advanceHeld();

            if ($open->minor > 0) {
                $items[] = self::item('order', $order, $order->order_number, $order->created_at, null, $open);
            }
        }

        return $items;
    }

    /** The customer's credit on 2150 Customer Advances on the date (what we hold of theirs). */
    public static function customerAdvance(Customer $customer, ?Carbon $asAt = null): Money
    {
        $account = Account::bySlug('customer_advances');

        if ($account === null) {
            return Money::zero();
        }

        $sum = (int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.party_type', $customer->getMorphClass())
            ->where('journal_lines.party_id', $customer->id)
            ->when($asAt, fn ($q) => $q->whereDate('journals.journal_date', '<=', $asAt->toDateString()))
            ->sum('journal_lines.amount_minor');

        return Money::ofMinor(max(0, -$sum));
    }

    /** @return array<string, mixed> */
    private static function item(string $kind, Model $document, ?string $number, mixed $date, mixed $due, Money $open): array
    {
        $show = fn (mixed $value) => $value === null ? null : Carbon::parse($value)->toDateString();

        return [
            'ref' => "{$kind}:{$document->getKey()}",
            'kind' => $kind,
            'id' => (int) $document->getKey(),
            'number' => (string) ($number ?? "#{$document->getKey()}"),
            'date' => $show($date),
            'due_date' => $show($due),
            'open' => $open,
            'document' => $document,
        ];
    }
}
