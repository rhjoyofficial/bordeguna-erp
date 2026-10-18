<?php

namespace App\Modules\Inventory\Enums;

/**
 * PR3 (18 Oct 2026): what a cost line on a production run is for. Tally's
 * "additional cost" on a Manufacturing Journal, named so the run page and the
 * journal memo can say what it was.
 */
enum ProductionCostKind: string
{
    case Labour = 'labour';
    case Overhead = 'overhead';
    case PackagingLabour = 'packaging_labour';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labour => 'Labour',
            self::Overhead => 'Overhead',
            self::PackagingLabour => 'Packaging labour',
            self::Other => 'Other cost',
        };
    }

    /**
     * The chart code suggested for this kind on a new run, when the business
     * has it (looked up, never assumed: a live chart may differ). The person
     * can always pick another head.
     */
    public function suggestedAccountCode(): ?string
    {
        return match ($this) {
            self::Labour => '6097',
            self::Overhead => '6052',
            self::PackagingLabour => '6201',
            self::Other => null,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $kind) => ['value' => $kind->value, 'label' => $kind->label()], self::cases());
    }
}
