<?php

namespace App\Modules\Assets\Enums;

/**
 * Who an outside custodian is (slice A2). A repair shop puts the thing Under
 * repair; anyone else holds it With an outside party.
 */
enum AssetPartyKind: string
{
    case RepairShop = 'repair_shop';
    case Freelancer = 'freelancer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RepairShop => 'Repair shop',
            self::Freelancer => 'Freelancer',
            self::Other => 'Other',
        };
    }

    public function statusWhileHolding(): AssetStatus
    {
        return $this === self::RepairShop ? AssetStatus::UnderRepair : AssetStatus::WithOutsideParty;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
