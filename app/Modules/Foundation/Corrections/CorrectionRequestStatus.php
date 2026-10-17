<?php

namespace App\Modules\Foundation\Corrections;

enum CorrectionRequestStatus: string
{
    case Pending = 'pending';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case FailedStale = 'failed_stale';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Executed => 'Done',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::FailedStale => 'Failed, the record changed',
        };
    }
}
