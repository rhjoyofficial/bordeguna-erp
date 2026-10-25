<?php

namespace App\Modules\Purchasing\Enums;

use App\Modules\Purchasing\Support\CostTypeDefaults;

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
 *
 * ⭐ 18 Oct 2026 (purchase redesign PR1): `insurance` (goods in transit) and
 * `duty` (customs duty and other non-recoverable import charges) are new
 * values, both INTO the stock cost by default (IAS 2 para 11: import duties
 * and other non-recoverable taxes, transport and handling are part of the
 * cost of purchase). Expensed only by an override with its reason, naming the
 * expense head, as "packaging" already works.
 *
 * ⭐⭐ 24 Oct 2026, Joy's answer Q15 (initial plan B10, OVERRIDES D-A above):
 * EVERY extra cost paid while purchasing (carrying, labour, conveyance,
 * courier, feeding, others) is product sourcing cost BY DEFAULT: it goes into
 * the stock cost, apportioned, and the supplier is owed only the product
 * price. A line may still be switched to expense with a reason, but that is
 * never the default. Product profit and loss always includes these costs.
 * `conveyance`, `courier` and `feeding` are new values; the stored values of
 * the old cases never change. PROSPECTIVE as before: a saved line keeps the
 * default that applied when it was saved (landed_cost_lines.default_treatment).
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
    case Insurance = 'insurance';
    case Duty = 'duty';
    // ⭐ 24 Oct 2026 (Q15, R47): Joy's own cost types.
    case Conveyance = 'conveyance';
    case Courier = 'courier';
    case Feeding = 'feeding';

    public function label(): string
    {
        return match ($this) {
            self::Packaging => 'Packaging',
            self::Freight => 'Carrying / carriage inward',
            self::Labour => 'Unloading / handling labour',
            self::GeneralLabour => 'General / admin labour',
            self::Travel => 'Travel / market visit',
            self::BankCharge => 'Bank / payment charge',
            self::Other => 'Other cost',
            self::Insurance => 'Insurance in transit',
            self::Duty => 'Customs duty / import charges',
            self::Conveyance => 'Conveyance',
            self::Courier => 'Courier',
            self::Feeding => 'Feeding cost',
        };
    }

    public function defaultTreatment(): LandedCostTreatment
    {
        // ⭐ Q15 (24 Oct 2026): every type into the stock cost, no exception,
        // UNTIL the Owner changes that type's row (Joy's answer 3, 25 Oct 2026:
        // Finance policy > Purchase cost types, per business). A saved line's
        // own default (and the override-reason rule) still read as before.
        return CostTypeDefaults::treatmentOf($this);
    }

    /**
     * ⭐ Answer 3 (25 Oct 2026): the expense head the Owner set for this type
     * when its default is expense; null when the line must name one (or the
     * type's mapping key answers). Read with the default treatment, never on
     * its own.
     */
    public function defaultExpenseAccountId(): ?int
    {
        return CostTypeDefaults::expenseAccountIdOf($this);
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
            self::Travel, self::Conveyance => 'purchasing.landed_cost.travel',
            self::BankCharge => 'purchasing.landed_cost.bank_charge',
            self::Courier => 'purchasing.landed_cost.freight',
            self::Packaging, self::Other, self::Insurance, self::Duty, self::Feeding => null,
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
            self::Insurance => ['insurance'],
            self::Duty => ['duty', 'customs', 'import'],
            self::Conveyance => ['conveyance'],
            self::Courier => ['courier'],
            self::Feeding => ['feeding', 'food', 'meal'],
        };
    }

    /** @return list<array{value: string, label: string, default_treatment: string, default_expense_account_id: ?int}> */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'default_treatment' => $type->defaultTreatment()->value,
            // ⭐ Answer 3: the head a popup pre-fills when the default is expense.
            'default_expense_account_id' => $type->defaultExpenseAccountId(),
        ], self::cases());
    }
}
