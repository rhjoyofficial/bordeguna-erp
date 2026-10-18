<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ⭐ Automatic batch numbers and label dates (purchase redesign PR2, 18 Oct
 * 2026; Joy: "product batch numbers auto-generate"). Tally keeps batches on
 * every inward line but leaves the name to the clerk; we name them.
 *
 * The pattern is `<SKU>-<YYMMDD>-<seq>`: the product's SKU (or `P{id}` when it
 * has none that survives cleaning), the day the stock came in, and a two-digit
 * sequence per product and day, so the third delivery of HNY-500 on 21 Sep
 * 2026 is `HNY-500-260921-03`. Unique per business and product because the
 * sequence is read from that product's own batches.
 *
 * ⚠⚠ ONE copy, read by every door stock comes in by: a goods receipt (and so
 * Buy and receive), a production run's output and opening stock. Four
 * hand-written patterns would drift the first week.
 *
 * ⚠ The person may always type their own number (the supplier's lot on the
 * label). A typed number is kept as typed. Only a number in OUR pattern that
 * is already taken is replaced by the next free one: that is a suggestion the
 * screen showed which somebody else used first (two lines of one product, or
 * two receipts at once), never a choice.
 *
 * Label dates follow Tally: the manufacture date defaults to the day the stock
 * came in, and the expiry to manufacture plus the product's shelf life when
 * the product states one (`products.shelf_life_days`). Nothing is guessed
 * when it does not.
 */
final class BatchNumbers
{
    /** The prefix, `HNY-500-260921-`, for one product on one day. */
    public static function prefixFor(Product $product, CarbonInterface|string|null $on = null): string
    {
        return self::codeOf($product).'-'.self::day($on)->format('ymd').'-';
    }

    /** The next free number for this product on this day. */
    public static function next(Product $product, CarbonInterface|string|null $on = null): string
    {
        $prefix = self::prefixFor($product, $on);

        // ⚠ Without the business scope and read by product, so a console job
        // (an import, the nightly sweep) numbers exactly as the screen does.
        // The prefix holds only A-Z, 0-9 and dashes (codeOf), so nothing in
        // it is a LIKE wildcard.
        $taken = StockBatch::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('batch_number', 'like', $prefix.'%')
            ->pluck('batch_number');

        $highest = 0;

        foreach ($taken as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $number, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The number a new batch gets: blank means automatic; a number in our own
     * pattern that is already taken moves to the next free one; anything else
     * the person typed is kept as typed.
     */
    public static function resolve(Product $product, ?string $typed, CarbonInterface|string|null $on = null): string
    {
        $typed = $typed === null ? '' : trim($typed);

        if ($typed === '') {
            return self::next($product, $on);
        }

        if (self::isOurs($product, $typed) && self::isTaken($product, $typed)) {
            // ⚠ The next free number for the day the SUGGESTION named, so a
            // stale suggestion never jumps to another day's sequence.
            $day = substr($typed, strlen(self::codeOf($product)) + 1, 6);

            return self::next($product, Carbon::createFromFormat('ymd', $day));
        }

        return $typed;
    }

    /** Expiry from manufacture plus the product's shelf life, or null. */
    public static function defaultExpiry(Product $product, ?string $manufacturedDate): ?string
    {
        $days = $product->shelf_life_days;

        if ($days === null || (int) $days <= 0 || $manufacturedDate === null || trim($manufacturedDate) === '') {
            return null;
        }

        return Carbon::parse($manufacturedDate)->addDays((int) $days)->toDateString();
    }

    /**
     * The batch number and label dates a new batch is stored with, from what
     * the person typed (each may be blank) and the day the stock came in.
     *
     * @return array{batch_number: string, manufactured_date: string, expiry_date: ?string}
     */
    public static function label(Product $product, ?string $batchNumber, ?string $manufacturedDate, ?string $expiryDate, CarbonInterface|string|null $on = null): array
    {
        $made = self::blankToNull($manufacturedDate) ?? self::day($on)->toDateString();
        $expires = self::blankToNull($expiryDate) ?? self::defaultExpiry($product, $made);

        return [
            'batch_number' => self::resolve($product, $batchNumber, $on),
            'manufactured_date' => Carbon::parse($made)->toDateString(),
            'expiry_date' => $expires === null ? null : Carbon::parse($expires)->toDateString(),
        ];
    }

    /** True when the number is in our pattern for this product. */
    public static function isOurs(Product $product, string $number): bool
    {
        return preg_match('/^'.preg_quote(self::codeOf($product), '/').'-\d{6}-\d{2,}$/', $number) === 1;
    }

    /**
     * The SKU, upper case, with anything but letters and digits folded to a
     * single dash and the ends trimmed, at most 24 characters; `P{id}` when
     * nothing is left (a product with no SKU, or one in Bangla script).
     */
    public static function codeOf(Product $product): string
    {
        $code = strtoupper((string) $product->sku);
        $code = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $code), '-');
        $code = rtrim(substr($code, 0, 24), '-');

        return $code === '' ? 'P'.$product->id : $code;
    }

    private static function isTaken(Product $product, string $number): bool
    {
        return StockBatch::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('batch_number', $number)
            ->exists();
    }

    private static function day(CarbonInterface|string|null $on): CarbonInterface
    {
        // ⚠ The app clock is Asia/Dhaka, so the day in the number is the
        // storekeeper's day, never UTC's.
        return $on === null ? Carbon::now() : Carbon::parse($on);
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
