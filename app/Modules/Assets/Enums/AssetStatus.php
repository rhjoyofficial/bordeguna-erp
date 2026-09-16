<?php

namespace App\Modules\Assets\Enums;

/**
 * Where an item stands in its life (asset management plan §2.3, 16 September
 * 2026). Kept apart from its CONDITION: "in use, and poor" is a real state.
 *
 * ⚠ Only the first three are set by hand while an item is registered or
 * corrected. The rest belong to the documents that cause them: sent to an
 * outside party (slice A2), found missing at a stock-take (A3), disposed (A5).
 */
enum AssetStatus: string
{
    case InStore = 'in_store';
    case InUse = 'in_use';
    case UnderRepair = 'under_repair';
    case WithOutsideParty = 'with_outside_party';
    case Missing = 'missing';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::InStore => 'In store',
            self::InUse => 'In use',
            self::UnderRepair => 'Under repair',
            self::WithOutsideParty => 'With an outside party',
            self::Missing => 'Missing',
            self::Disposed => 'Disposed',
        };
    }

    /**
     * What a person may choose on the register's own form.
     *
     * @return list<self>
     */
    public static function settableByHand(): array
    {
        return [self::InStore, self::InUse, self::UnderRepair];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $cases): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }
}
