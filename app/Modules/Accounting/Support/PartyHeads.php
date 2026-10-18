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
 * ⭐ 20 Oct 2026 (Joy: "how do I give them money or how do they give me?"),
 * the rest of Tally's party ledger, each through the document it already had:
 *
 *   supplier on a Receive voucher  -> what they owe us back (SREF, Cr 2110) or
 *                                     an advance they return (SARF, Cr 1160)
 *   customer on a Payment voucher  -> their advance back (Dr 2150), a return's
 *                                     credit or an order's money (customer
 *                                     refund, Dr 1140)
 *   either on a Journal voucher    -> the typed party adjustment (write-off,
 *                                     discount, set-off), as ChatGPT ruled
 *
 * What each line is against is Tally's bill-wise details (PartyBillWise).
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

    /**
     * Every customer, supplier and employee head in the SHARED chart, of every
     * business (ACC-001, 14 Oct 2026). `map()` keeps to this business's people,
     * which is right for the voucher picker but wrong for the heading rule: a
     * head created under another business's "1140-317 Rahim" is still a raw
     * receivable that no invoice or party explains. So the tenant scope is
     * lifted here, and only here.
     *
     * @return list<int>
     */
    public static function allHeadIds(): array
    {
        $ids = [];

        foreach (array_keys(self::CLASSES) as $class) {
            foreach ($class::withoutGlobalScopes()->whereNotNull('account_id')->pluck('account_id') as $accountId) {
                $ids[] = (int) $accountId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The heads that sit, at any depth, under a person's own head: the ones
     * ACC-001 created before 14 Oct 2026. Nothing may post to them any more.
     *
     * @return list<int>
     */
    public static function headsBeneathPeople(): array
    {
        $frontier = self::allHeadIds();
        $found = [];

        while ($frontier !== []) {
            $children = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $frontier = array_values(array_diff($children, $found));
            $found = [...$found, ...$frontier];
        }

        return $found;
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

    /**
     * Why this person's head cannot sit on this voucher type, or null when it
     * can. Since 20 Oct 2026 every voucher with lines takes every party; what
     * a line may do is then refused at posting, by what is open (PartyBillWise).
     */
    public static function refusal(string $class, VoucherType $type): ?string
    {
        return $type->hasLines() ? null : 'A contra voucher moves money between your own accounts only.';
    }
}
