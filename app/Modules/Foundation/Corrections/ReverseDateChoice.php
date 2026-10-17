<?php

namespace App\Modules\Foundation\Corrections;

/** Reverse only may pick Today, and only in an open month (PHASE-0-CONTRACT v2, 1.9). */
enum ReverseDateChoice: string
{
    case OriginalDate = 'original';
    case Today = 'today';
}
