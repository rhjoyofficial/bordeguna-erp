<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Carbon;

/**
 * ⭐ Samples (Joy, 25 Oct 2026): where a sample's cost and its delivery go.
 *
 *   goods     the mapping `sales.samples` when somebody set one, else the head
 *             "Samples and Marketing"
 *   delivery  the mapping `sales.samples_delivery`, else the head "Sample
 *             Delivery"
 *
 * Both heads are created on first use when the chart lacks them, the way
 * GiftExpenseAccount creates 6331: under a 6300 Selling expenses group when
 * the chart has one (the master chart of 5 Sep 2026 moved 6300 away, so most
 * charts do not), else beside 6331 Customer Gift under 6330 Gifts and
 * Promotional, else under 6000. A head somebody renamed or re-coded is found
 * again by its slug.
 *
 * ⚠ Created, never re-pointed: setting a mapping later moves NEW samples only.
 */
final class SampleHeads
{
    public const GOODS_MAPPING = 'sales.samples';

    public const DELIVERY_MAPPING = 'sales.samples_delivery';

    public static function goods(Carbon $on): Account
    {
        return AccountMapping::forKeyOn(self::GOODS_MAPPING, $on)?->account
            ?? self::head('samples_and_marketing', 'Samples and Marketing', '6338', 'The cost of goods given away free as samples (tasting, gift, marketing).');
    }

    public static function delivery(Carbon $on): Account
    {
        return AccountMapping::forKeyOn(self::DELIVERY_MAPPING, $on)?->account
            ?? self::head('sample_delivery', 'Sample Delivery', '6339', 'What we pay to deliver free samples (courier, rider or by hand).');
    }

    private static function head(string $slug, string $name, string $preferredCode, string $note): Account
    {
        $existing = Account::bySlug($slug);

        if ($existing !== null) {
            return $existing;
        }

        $selling = Account::query()->where('code', '6300')->where('type', AccountType::Expense)->where('is_postable', false)->first();
        $parent = $selling
            ?? Account::query()->where('code', '6330')->first()
            ?? Account::query()->where('code', '6000')->first();

        $account = new Account([
            'code' => self::freeCode($selling === null ? $preferredCode : '63'.substr($preferredCode, 2)),
            'name' => $name,
            'type' => AccountType::Expense,
            'parent_id' => $parent?->id,
            'is_postable' => true,
            'note' => $note,
        ]);
        $account->system_slug = $slug;
        $account->is_active = true;
        $account->save();

        return $account;
    }

    /** The code asked for, or the next one up that nobody holds. */
    private static function freeCode(string $code): string
    {
        $candidate = (int) $code;

        while (Account::query()->where('code', (string) $candidate)->exists()) {
            $candidate++;
        }

        return (string) $candidate;
    }
}
