<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;

/**
 * How much a person may give off a line and off a bill.
 *
 * ⭐⭐ 25 Oct 2026 (Joy, Q12 = A, R89): a discount is a PERCENT per line, capped
 * by role, and the cap holds on EVERY LINE, not only on the bill:
 *
 * - Owner: no cap.
 * - Manager, or a person given `sales.discount.in_charge`: 5% of the line.
 * - Everybody else (staff): 2% of the line.
 *
 * Both percentages are the Finance policy's (Settings > Finance policy), so
 * the Owner changes them there. The Tk 300 staff ceiling of 26 Sep 2026 is
 * gone with this answer (option C, "keep today", was not chosen); its column
 * stays in the database and nothing reads it.
 *
 * "The line" is the line at LIST price (the price the buyer would have paid
 * untouched), so a price typed below the list counts as given, exactly like a
 * line discount. A line with no list price is measured against its own price.
 * A price typed ABOVE the list gives nothing back, and a promotion's free line
 * is the business's offer, not the cashier's, so it is never counted.
 *
 * The whole bill is then held to the same percent of all its goods, with the
 * bill discount added in, so a discount cannot slip past the line cap by being
 * typed on the bill instead.
 */
final class DiscountCap
{
    /**
     * @param  list<array<string, mixed>>  $lines  quantity, unitPrice, discount, listPrice?, name? (or product?)
     */
    public static function refusal(User $user, int $businessId, array $lines, string $billDiscount): ?string
    {
        $percent = self::percentFor($user, $businessId);

        if ($percent === null) {
            return null;
        }

        foreach ($lines as $line) {
            if (($refusal = self::lineRefusal($user, $businessId, $line)) !== null) {
                return $refusal;
            }
        }

        ['goods' => $goods, 'given' => $given] = self::measure($lines, $billDiscount);

        if ($given <= 0 || $given * 100 <= $goods * $percent) {
            return null;
        }

        return sprintf(
            'This bill gives %s off %s of goods. Your limit is %s (%d%% of the goods). Give less, or ask %s.',
            Money::ofMinor($given)->format(),
            Money::ofMinor($goods)->format(),
            Money::ofMinor(intdiv($goods * $percent, 100))->format(),
            $percent,
            $user->hasRole('Manager') ? 'the Owner' : 'a Manager or the Owner',
        );
    }

    /**
     * Why ONE line gives more than this person may, or null. The screens call
     * it per line so the sentence lands under that line's Discount % box.
     *
     * @param  array<string, mixed>  $line  quantity, unitPrice, discount, listPrice?, name? (or product?)
     */
    public static function lineRefusal(User $user, int $businessId, array $line): ?string
    {
        $percent = self::percentFor($user, $businessId);

        if ($percent === null) {
            return null;
        }

        ['goods' => $goods, 'given' => $given] = self::measureLine($line);

        if ($given <= 0 || $given * 100 <= $goods * $percent) {
            return null;
        }

        // The counter's priced lines carry the product itself.
        $name = trim((string) ($line['name'] ?? (isset($line['product']) ? $line['product']->name : ''))) ?: 'A line';

        return sprintf(
            '%s gives %s off %s, which is %s%%. Your limit is %d%% a line. Give less, or ask %s.',
            $name,
            Money::ofMinor($given)->format(),
            Money::ofMinor($goods)->format(),
            self::percentOf($given, $goods),
            $percent,
            $user->hasRole('Manager') ? 'the Owner' : 'a Manager or the Owner',
        );
    }

    /**
     * The percent this person may give off a line, or null for no cap. The
     * screens show it beside the Discount % box.
     */
    public static function percentFor(User $user, int $businessId): ?int
    {
        if ($user->hasRole('Owner')) {
            return null;
        }

        $policy = FinancePolicy::for($businessId);

        if ($user->hasRole('Manager') || $user->can('sales.discount.in_charge')) {
            return (int) ($policy->manager_discount_cap_percent ?? 5);
        }

        return (int) ($policy->staff_discount_cap_percent ?? 2);
    }

    /**
     * The two numbers the bill cap compares, in paisa: the goods at LIST price
     * and everything given off them (price cuts below the list, line discounts,
     * the bill discount). Split out on 14 Oct 2026 (rule #3) so the shop order
     * import can keep the same figures on the order for audit.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{goods: int, given: int}
     */
    public static function measure(array $lines, string $billDiscount): array
    {
        $goods = 0;
        $given = Money::ofTaka($billDiscount)->minor;

        foreach ($lines as $line) {
            $measured = self::measureLine($line);
            $goods += $measured['goods'];
            $given += $measured['given'];
        }

        return ['goods' => $goods, 'given' => $given];
    }

    /**
     * One line: its goods at list price (or its own price when it has no
     * list) and what was given off them.
     *
     * @param  array<string, mixed>  $line
     * @return array{goods: int, given: int}
     */
    public static function measureLine(array $line): array
    {
        $list = isset($line['listPrice']) ? (string) $line['listPrice'] : null;
        $quantity = (string) $line['quantity'];
        $unitPrice = (string) $line['unitPrice'];
        $discount = (string) ($line['discount'] ?? '0');

        $goods = Money::ofProduct($quantity, $list ?? $unitPrice)->minor;
        $given = Money::ofTaka($discount === '' ? '0' : $discount)->minor;

        if ($list !== null) {
            $given += max(0, Money::ofProduct($quantity, bcsub($list, $unitPrice, 4))->minor);
        }

        return ['goods' => $goods, 'given' => $given];
    }

    /** "2.35": a share as a percent, two places, for the refusal sentence. */
    private static function percentOf(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '100';
        }

        return rtrim(rtrim(number_format($part * 100 / $whole, 2, '.', ''), '0'), '.');
    }
}
