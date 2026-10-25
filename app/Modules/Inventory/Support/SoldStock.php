<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Carbon;

/**
 * ⭐ The Tk 10,000 sold-stock rule (corrections wave 1 gaps, 24 Oct 2026;
 * FINAL-PLAN 4.3 "Exceptions, named in the dialog", section 9 answer 2:
 * accepted by Joy and the accountant).
 *
 *   A receipt, a found-stock adjustment or a production run brought units into
 *   a batch; some were sold or used since; now the document is reversed or
 *   corrected. Past sales are NEVER re-costed. What is left goes back out of
 *   the batch on the document's own date; the cost of what was already sold
 *   stays where the sales put it, and the difference the correction leaves
 *   between the ledger and the stock posts TODAY to its own cost-of-sales head
 *   (PostStockCostDifference). Up to Tk 10,000 anyone who may correct the
 *   document does it; above that, only the Owner.
 *
 * Refused in a sentence (the old "used, sold, moved or reserved" refusal, now
 * only where it is really unsafe):
 *   - units reserved for an order (release them first);
 *   - units moved to another warehouse or sent back to the supplier (those
 *     units still exist elsewhere, so "used" would be untrue);
 *   - the batch's cost changed since (a landed cost, a discount, a
 *     revaluation: the units would leave at another cost than they came in);
 *   - the batch holds more than this movement brought in;
 *   - the batch ran lower at some point than what is left today, so taking
 *     the rest out on the original day would make its history negative
 *     (BatchHistory: FIFO/FEFO never negative, at every point in time).
 */
final class SoldStock
{
    /** Tk 10,000, in paisa. */
    public const LIMIT_MINOR = 1_000_000;

    /** @param list<int> $ownIds movements that belong to the document itself and are never "used" */
    public static function share(StockMovement $in, array $ownIds = []): ?SoldStockShare
    {
        if ($in->direction !== MovementDirection::In || $in->batch_id === null) {
            return null;
        }

        $batch = StockBatch::query()->find($in->batch_id);

        if ($batch === null) {
            return null;
        }

        $name = $batch->batch_number ?? "#{$batch->id}";
        $current = self::scaled((string) $batch->current_quantity);
        $brought = self::scaled((string) $in->quantity);
        $remaining = max(0, min($current, $brought));
        $used = $brought - $remaining;

        $others = StockMovement::query()
            ->where('batch_id', $batch->id)
            ->whereKeyNot($in->id)
            ->whereNotIn('id', $ownIds)
            ->get(['id', 'direction', 'reason', 'quantity', 'reverses_movement_id', 'occurred_at']);

        $refusal = match (true) {
            StockReservation::query()->where('stock_batch_id', $batch->id)->whereNull('released_at')->exists() => "Some of batch {$name} is reserved for an order, so this cannot be undone until that reservation is released.",
            $others->contains(fn ($m) => $m->reason === MovementReason::Transfer && $m->direction === MovementDirection::Out) => "Goods from batch {$name} were moved to another warehouse since, so this cannot be undone here until they are moved back.",
            $others->contains(fn ($m) => $m->reason === MovementReason::PurchaseReturn) => "Goods from batch {$name} went back to the supplier since, so reverse that return first.",
            $others->contains(fn ($m) => self::scaled((string) $m->quantity) === 0) => "Batch {$name}'s cost was changed since by a landed cost, a discount or a revaluation, so this cannot be undone here.",
            $current > $brought => "Batch {$name} holds more than this brought in, so this cannot be undone here.",
            $remaining > 0 && BatchHistory::lowestScaledFrom($in) < $remaining => "Batch {$name} ran lower than what is left today at some point, so taking the rest out on ".Carbon::parse($in->occurred_at)->format('j M Y').' would make its history negative.',
            default => null,
        };

        // What the used units cost when they came in: the incoming value less
        // the value of what is left, each rounded once (the StockValue rule).
        // ⭐ 26 Oct 2026: what is left is worth its proportional share of the
        // batch's exact value, the very figure the take-back movement will
        // carry (StockMovementObserver), so the difference is to the paisa.
        $cost = StockValue::of($in)->absolute()->minor
            - StockValue::share((int) $batch->current_value, $remaining, $current);

        return new SoldStockShare($in, $batch, self::unscaled($remaining), self::unscaled($used), $used > 0 ? $cost : 0, $refusal);
    }

    public static function aboveLimit(int $costMinor): bool
    {
        return abs($costMinor) > self::LIMIT_MINOR;
    }

    /**
     * Above Tk 10,000, only the Owner (or the Owner approving the request).
     * One sentence, or null.
     */
    public static function ownerBlocker(int $costMinor, ?User $user, ?User $approver = null): ?Blocker
    {
        if (! self::aboveLimit($costMinor) || Owners::is($user) || Owners::is($approver)) {
            return null;
        }

        return new Blocker(
            'sold_stock_owner',
            'Goods that cost '.Tk::of(abs($costMinor)).' were already sold or used, and above '.Tk::of(self::LIMIT_MINOR).' only the Owner can put that cost difference through.',
            resolvableBy: 'owner',
        );
    }

    /** The preview sentence for the cost difference, dated today. */
    public static function effectSentence(int $differenceMinor, string $usedQuantity, ?string $unit, ?string $product): string
    {
        $quantity = rtrim(rtrim(number_format((float) $usedQuantity, 4, '.', ','), '0'), '.');
        $what = trim(preg_replace('/\s+/', ' ', "{$quantity} {$unit} of {$product}") ?? '');
        $direction = $differenceMinor >= 0 ? 'comes off' : 'is added to';

        return "{$what} were already sold or used: their cost stays on those sales, and ".Tk::of(abs($differenceMinor))." {$direction} cost of sales today (".Tk::date(Carbon::today()).') as a stock cost correction.';
    }

    public static function scaled(string $quantity): int
    {
        return (int) round((float) $quantity * 10000);
    }

    public static function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
