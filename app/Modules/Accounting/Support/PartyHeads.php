<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer, supplier or employee picked straight onto a voucher line by its
 * own head ("1140-317 Rahim - Bor de Guna"), finance decision C3, 29 Sep 2026.
 *
 * ⚠⚠ THE HEAD STAYS A NON-POSTABLE HEADING (EntityLedgers). Nothing is ever
 * posted to it: PostVoucher turns the pick into the right document and control
 * account with the person attached -
 *
 *   customer on a Receive voucher  -> a customer receipt against open invoices
 *                                     (Cr 1140 + customer); any rest is an
 *                                     advance (Cr 2150 + customer)
 *   supplier on a Payment voucher  -> a supplier payment against open bills
 *                                     (Dr 2110 + supplier); any rest is an
 *                                     advance (Dr 1160 + supplier)
 *   employee on any voucher        -> Employee Advances 1170 + employee
 *
 * A customer on a Payment voucher (a refund), a supplier on a Receive voucher,
 * and either on a Journal voucher (write-off, discount, set-off) are refused:
 * each has its own document, and ChatGPT's decision for journal party lines is
 * a typed party adjustment, not a free line.
 */
final class PartyHeads
{
    /** @var array<class-string<Model>, string> */
    private const CLASSES = [
        Customer::class => 'customer',
        Supplier::class => 'supplier',
        Employee::class => 'employee',
    ];

    /**
     * Head account id => entity class, for THIS business's people only (the
     * models' tenant scope). Another business's head reads as a plain heading.
     *
     * @return array<int, class-string<Model>>
     */
    public static function map(): array
    {
        $map = [];

        foreach (array_keys(self::CLASSES) as $class) {
            foreach ($class::query()->whereNotNull('account_id')->pluck('account_id') as $accountId) {
                $map[(int) $accountId] = $class;
            }
        }

        return $map;
    }

    public static function entityFor(Account $head): ?Model
    {
        if ($head->is_postable) {
            return null;
        }

        foreach (array_keys(self::CLASSES) as $class) {
            $entity = $class::query()->where('account_id', $head->id)->first();

            if ($entity !== null) {
                return $entity;
            }
        }

        return null;
    }

    /** Why this person's head cannot sit on this voucher type, or null when it can. */
    public static function refusal(string $class, VoucherType $type): ?string
    {
        return match (true) {
            $class === Employee::class => null,
            $class === Customer::class && $type === VoucherType::Receipt => null,
            $class === Supplier::class && $type === VoucherType::Payment => null,
            $class === Customer::class && $type === VoucherType::Payment => 'Money back to a customer is refunded from its sales return or its order.',
            $class === Supplier::class && $type === VoucherType::Receipt => 'Money back from a supplier is recorded on Purchases > Credit notes.',
            default => 'A customer or supplier on a journal (write-off, discount, set-off) is a party adjustment, not a journal line.',
        };
    }
}
