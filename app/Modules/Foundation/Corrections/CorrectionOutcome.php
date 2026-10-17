<?php

namespace App\Modules\Foundation\Corrections;

enum CorrectionOutcome: string
{
    case Executed = 'executed';
    case Requested = 'requested';
    case AlreadyDone = 'already_done';
}
