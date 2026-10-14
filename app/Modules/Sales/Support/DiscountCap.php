<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;

/**
 * How much a person may give off one bill (Joy, 26 Sep 2026):
 *
 * - Owner: no cap.
 * - Manager, or a person given `sales.discount.in_charge`: 3% of the goods.
 * - Anyone else allowed to override a price: 1% of the goods or Tk 300,
 *   whichever is LOWER.
 *
 * "The goods" is the bill at LIST price, so the cap is measured against what
 * the bill would have been, and it leaves out delivery and VAT (a counter
 * bill carries no delivery line; VAT is applied after the discount). What
 * counts as given: a price typed below the list, each line discount, and the
 * whole-bill discount. A price typed ABOVE the list gives nothing back, and
 * a promotion's free line is the business's offer, not the cashier's, so it
 * is added after this check and never counted.
 *
 * The percentages and the taka ceiling are the Finance policy's, so the
 * Owner changes them there.
 */
final class DiscountCap
{
    /**
     * @param  list<array{quantity: string, unitPrice: string, discount: string, listPrice?: ?string}>  $lines
     */
    public static function refusal(User $user, int $businessId, array $lines, string $billDiscount): ?string
    {
        if ($user->hasRole('Owner')) {
            return null;
        }

        ['goods' => $goods, 'given' => $given] = self::measure($lines, $billDiscount);

        if ($given <= 0) {
            return null;
        }

        $policy = FinancePolicy::for($businessId);
        $inCharge = $user->hasRole('Manager') || $user->can('sales.discount.in_charge');

        if ($inCharge) {
            $percent = (int) ($policy->manager_discount_cap_percent ?? 3);
            $limit = intdiv($goods * $percent, 100);
            $rule = "{$percent}% of the goods";
        } else {
            $percent = (int) ($policy->staff_discount_cap_percent ?? 1);
            $ceiling = (int) ($policy->staff_discount_cap_minor ?? 30_000);
            $limit = min(intdiv($goods * $percent, 100), $ceiling);
            $rule = "{$percent}% of the goods or ".Money::ofMinor($ceiling)->format().', whichever is lower';
        }

        if ($given <= $limit) {
            return null;
        }

        return sprintf(
            'This bill gives %s off %s of goods. Your limit is %s (%s). Give less, or ask the Owner.',
            Money::ofMinor($given)->format(),
            Money::ofMinor($goods)->format(),
            Money::ofMinor($limit)->format(),
            $rule,
        );
    }

    /**
     * The two numbers the cap compares, in paisa: the goods at LIST price and
     * everything given off them (price cuts below the list, line discounts,
     * the bill discount). A line with no list price has no yardstick and is
     * left out. Split out on 14 Oct 2026 (rule #3) so the shop order import
     * can keep the same figures on the order for audit.
     *
     * @param  list<array{quantity: string, unitPrice: string, discount: string, listPrice?: ?string}>  $lines
     * @return array{goods: int, given: int}
     */
    public static function measure(array $lines, string $billDiscount): array
    {
        $goods = 0;
        $given = Money::ofTaka($billDiscount)->minor;

        foreach ($lines as $line) {
            $list = $line['listPrice'] ?? null;

            if ($list === null) {
                continue;
            }

            $goods += Money::ofProduct($line['quantity'], $list)->minor;
            $given += max(0, Money::ofProduct($line['quantity'], bcsub($list, $line['unitPrice'], 4))->minor);
            $given += Money::ofTaka($line['discount'])->minor;
        }

        return ['goods' => $goods, 'given' => $given];
    }
}
