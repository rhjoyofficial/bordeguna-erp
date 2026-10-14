<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;

/**
 * "What happened?" on the Stock screen: the reason a person picks, and the
 * adjustment type the server derives from it.
 *
 * ⚠⚠ WHY THE SERVER DERIVES THE TYPE (21 Sep 2026). The adjustment endpoint
 * required BOTH a reason (the business's own pick list) and a type (the
 * enum), and the screen sent only the reason. Every correction typed on the
 * Stock screen was refused on a key the form did not render, so for weeks
 * nobody could record damage, loss or found stock at all. Asking a storekeeper
 * the same question twice in two vocabularies is what caused it; now they
 * answer once, and the type follows from the answer.
 *
 * ⚠ The type is not decoration: it is the ACCOUNT MAPPING KEY
 * (`inventory.adjustment.{type}`, PostInventoryAdjustmentJournal). Today every
 * type maps to the same write-off account, so a reason that falls through to
 * Manual correction posts exactly where it would have. The day an accountant
 * separates damaged from expired, a reason renamed in Settings > Reasons
 * ("Damaged" to "Broken") would start posting as Manual correction - which is
 * why the seeded wordings AND the enum's own labels are both recognised, and
 * why an unrecognised name lands on the neutral type rather than a guess.
 *
 * ⚠ A caller that sends `type` explicitly (a stock count, a test, a future
 * API) keeps it. Derivation is only the fallback for a person on a screen.
 */
final class AdjustmentCause
{
    /**
     * Normalised reason name => the type it means.
     *
     * The first entries are InventoryReasonsSeeder's own wordings; the rest are
     * the enum's labels, so a business that renames a reason to the label the
     * system itself uses is understood too.
     *
     * @var array<string, InventoryAdjustmentType>
     */
    private const TYPES_BY_REASON = [
        'damaged' => InventoryAdjustmentType::Damaged,
        'expired' => InventoryAdjustmentType::Expired,
        'theft or loss' => InventoryAdjustmentType::LostOrStolen,
        'lost or stolen' => InventoryAdjustmentType::LostOrStolen,
        'internal consumption' => InventoryAdjustmentType::InternalConsumption,
    ];

    /**
     * Types that can only take stock AWAY. Damaged, expired, lost and used-up
     * stock never raises the count; stock that turns up is "found", which is a
     * count correction, not a negative theft.
     *
     * ⚠ Presentation only - the screen fixes the direction for these. The
     * engine still takes whatever direction it is handed, because a stock
     * count and the existing tests legitimately post other combinations.
     */
    private const TAKES_EXPIRED_FIRST = [
        InventoryAdjustmentType::StockCountVariance,
        InventoryAdjustmentType::ManualCorrection,
        InventoryAdjustmentType::Expired,
    ];

    private const ONLY_REDUCE = [
        InventoryAdjustmentType::Damaged,
        InventoryAdjustmentType::Expired,
        InventoryAdjustmentType::LostOrStolen,
        InventoryAdjustmentType::InternalConsumption,
    ];

    public static function typeFor(Reason $reason): InventoryAdjustmentType
    {
        $name = strtolower(trim((string) preg_replace('/\s+/', ' ', $reason->name)));

        return self::TYPES_BY_REASON[$name] ?? InventoryAdjustmentType::ManualCorrection;
    }

    public static function onlyReduces(InventoryAdjustmentType $type): bool
    {
        return in_array($type, self::ONLY_REDUCE, true);
    }

    /**
     * Types whose DECREASE means "the shelf holds less than the book", so the
     * missing units are taken from EXPIRED batches first, then FIFO.
     *
     * ⚠⚠ 14 Oct 2026 (follow-up INV-001 to audit #14). The book a count is
     * sized against includes expired batches (the counter meets them on the
     * shelf), but FIFO only consumes unexpired ones. So when expired goods were
     * thrown away without a write-off, a count of 0 against 4 expired + 6 good
     * was REFUSED ("only 6 available"), and a count of 6 wrote off 4 GOOD units
     * at the good cost and left the expired batch on the book, which the
     * nightly expiry sweep then proposed to write off a second time.
     *
     * ⚠ Not damage, loss or internal use: those units left the shelf because
     * somebody took or broke them, which says nothing about their date, and
     * taking an expired batch for them would leave expired stock on the shelf
     * that the book no longer shows.
     */
    public static function takesExpiredFirst(InventoryAdjustmentType $type): bool
    {
        return in_array($type, self::TAKES_EXPIRED_FIRST, true);
    }

    /**
     * What the screen needs to know about one reason, to ask the right
     * follow-up question: "how much was lost" or "more or less than the book".
     *
     * @return array{id: int, name: string, type: string, only_reduces: bool}
     */
    public static function describe(Reason $reason): array
    {
        $type = self::typeFor($reason);

        return [
            'id' => $reason->id,
            'name' => $reason->name,
            'type' => $type->value,
            'only_reduces' => self::onlyReduces($type),
        ];
    }
}
