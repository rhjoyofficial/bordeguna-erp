<?php

namespace App\Modules\Foundation\Corrections;

/** Derived from the record, never picked by staff (PHASE-0-CONTRACT 1.1). */
enum DocumentStage: string
{
    case Draft = 'draft';
    case Waiting = 'waiting';
    case ApprovedNoEffect = 'approved_no_effect';
    case Done = 'done';
    case ListData = 'list_data';
    case LeftCompany = 'left_company';
}
