<?php

namespace App\Modules\Purchasing\Enums;

/**
 * What a landed-cost line paid for, and the treatment it gets by default.
 *
 * ⭐ 18 Oct 2026, Joy's decision D-A (supersedes the 17 Oct seed table of
 * ChatGPT ruling Q5): the defaults follow IAS 2 paras 10-11 and 16. Costs
 * that bring the goods to where they are sold (carriage inward, unloading and
 * handling, packaging that is part of the product) go INTO the stock cost;
 * general travel, admin labour, bank charges and anything unclassified stay an
 * expense. A line may override its default, but only with a reason, and the
 * override never changes the default for the next document.
 *
 * ⚠ The string values are stored on every line, so they never change: `labour`
 * is the unloading labour it always meant (relabelled), `freight` is carriage
 * inward. The new types are new values. PROSPECTIVE: a line keeps the default
 * that applied when it was saved (landed_cost_lines.default_treatment), so a
 * posted 17 Oct freight line that was expensed still reads as the default.
 */
enum LandedCostType: string
{
    case Packaging = 'packaging';
    case Freight = 'freight';
    case Labour = 'labour';
    case GeneralLabour = 'general_labour';
    case Travel = 'travel';
    case BankCharge = 'bank_charge';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Packaging => 'Packaging',
            self::Freight => 'Carriage inward / freight',
            self::Labour => 'Unloading / handling labour',
            self::GeneralLabour => 'General / admin labour',
            self::Travel => 'Travel / market visit',
            self::BankCharge => 'Bank / payment charge',
            self::Other => 'Other cost',
        };
    }

    public function defaultTreatment(): LandedCostTreatment
    {
        return match ($this) {
            self::Packaging, self::Freight, self::Labour => LandedCostTreatment::Capitalize,
            self::GeneralLabour, self::Travel, self::BankCharge, self::Other => LandedCostTreatment::Expense,
        };
    }

    /**
     * The mapping key of the expense head this type posts to when expensed.
     * `Packaging` and `Other` have none: the person names the head on the line.
     *
     * Seeded (InventoryAccountMappingSeeder): freight 5300 Freight and Carriage
     * Inward (cost of sales), both labour types 6096 Loading and Unloading
     * Labour (6201 on a chart without 6096), travel 6173 Market Visit (or the
     * chart's own travel head), bank charge 6471 Bank Charge (finance expense).
     */
    public function expenseMappingKey(): ?string
    {
        return match ($this) {
            self::Freight => 'purchasing.landed_cost.freight',
            self::Labour, self::GeneralLabour => 'purchasing.landed_cost.labour',
            self::Travel => 'purchasing.landed_cost.travel',
            self::BankCharge => 'purchasing.landed_cost.bank_charge',
            self::Packaging, self::Other => null,
        };
    }

    /**
     * Words in an expense head's name that mean the head holds this kind of
     * cost: what the double-counting check looks for on expense vouchers, beside
     * the type's own mapped head.
     *
     * @return list<string>
     */
    public function headKeywords(): array
    {
        return match ($this) {
            self::Packaging => ['packag'],
            self::Freight => ['freight', 'carriage', 'transport'],
            self::Labour, self::GeneralLabour => ['labour', 'labor', 'loading'],
            self::Travel => ['travel', 'conveyance', 'visit'],
            self::BankCharge => ['bank charge', 'transaction charge'],
            self::Other => [],
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
