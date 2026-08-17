<?php

namespace App\Modules\Inventory\Enums;

enum ProductionRunStatus: string
{
    case Draft = 'draft';
    // ⚠ Immutable from here (C-MFG-8). The correction path is Reversed, never
    // an edit: the movements and the journal both already said what happened.
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Reversed => 'Reversed',
        };
    }
}
