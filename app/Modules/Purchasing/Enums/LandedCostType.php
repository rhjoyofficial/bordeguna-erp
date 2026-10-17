<?php

namespace App\Modules\Purchasing\Enums;

/**
 * What a landed-cost line paid for (Phase 1, 17 Oct 2026, ChatGPT ruling Q5).
 *
 * ⭐ The SEED DEFAULT per type is the Owner-approved accounting policy, not a
 * convenience: packaging directly attributable to the goods is capitalised
 * (the existing packaging-cost policy); freight, unloading labour and bank
 * charges stay on the existing expense path; "other" is an expense until it
 * is classified. A line may override its default, but only with a reason,
 * and the override never changes the default for the next document.
 */
enum LandedCostType: string
{
    case Packaging = 'packaging';
    case Freight = 'freight';
    case Labour = 'labour';
    case BankCharge = 'bank_charge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Packaging => 'Packaging',
            self::Freight => 'Freight / carrying',
            self::Labour => 'Labour to unload',
            self::BankCharge => 'Bank / payment charge',
            self::Other => 'Other cost',
        };
    }

    public function defaultTreatment(): LandedCostTreatment
    {
        return match ($this) {
            self::Packaging => LandedCostTreatment::Capitalize,
            self::Freight, self::Labour, self::BankCharge, self::Other => LandedCostTreatment::Expense,
        };
    }

    /**
     * The mapping key of the expense head this type posts to when expensed.
     * `Other` has none: the person names the head on the line.
     *
     * Seeded (InventoryAccountMappingSeeder): freight 5300 Freight and Carriage
     * Inward (cost of sales), labour 6096 Loading and Unloading Labour, bank
     * charge 6471 Bank Charge (finance expense).
     */
    public function expenseMappingKey(): ?string
    {
        return match ($this) {
            self::Freight => 'purchasing.landed_cost.freight',
            self::Labour => 'purchasing.landed_cost.labour',
            self::BankCharge => 'purchasing.landed_cost.bank_charge',
            self::Packaging, self::Other => null,
        };
    }

    /** @return list<array{value: string, label: string, default_treatment: string}> */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'default_treatment' => $type->defaultTreatment()->value,
        ], self::cases());
    }
}
