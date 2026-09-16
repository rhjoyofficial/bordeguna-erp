<?php

namespace App\Modules\Assets\Enums;

/**
 * What a photo of an item shows (plan §2.7). Each is an attachment collection,
 * any number per item.
 *
 * ⚠ Hand-over, repair and disposal photos are taken by the documents that
 * cause them (slices A2, A4, A5), not uploaded loose on the item.
 */
enum AssetPhotoKind: string
{
    case Identity = 'identity';
    case Serial = 'serial';
    case Handover = 'handover';
    case Repair = 'repair';
    case Disposal = 'disposal';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'The item',
            self::Serial => 'Serial plate or label',
            self::Handover => 'Hand-over',
            self::Repair => 'Repair',
            self::Disposal => 'Disposal',
        };
    }

    /**
     * Uploaded on the item itself.
     *
     * @return list<self>
     */
    public static function onTheItem(): array
    {
        return [self::Identity, self::Serial];
    }
}
